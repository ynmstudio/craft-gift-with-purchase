<?php

namespace ynmstudio\giftwithpurchase\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\events\PurchasableAvailableEvent;
use craft\commerce\Plugin as Commerce;
use craft\commerce\services\Purchasables;
use craft\elements\Category;
use craft\db\Query;
use yii\base\Component;
use yii\base\Event;

use ynmstudio\giftwithpurchase\GiftWithPurchase;
use ynmstudio\giftwithpurchase\models\GiftRule;

class GiftCart extends Component
{
    /** @var bool Recursion guard */
    private $_isApplyingGifts = false;

    /** @var bool True only while _addGiftLineItem is executing */
    private $_isAddingGift = false;

    /** @var int[]|null Cached list of purchasable IDs with availability override */
    private $_overriddenPurchasableIds;

    /**
     * Register all event listeners for cart integration.
     * Isolated here for easy Commerce 5 upgrade path.
     */
    public function registerEventListeners()
    {
        // Clean up session data when order completes
        Event::on(
            Order::class,
            Order::EVENT_AFTER_COMPLETE_ORDER,
            [$this, 'handleOrderComplete']
        );

        // Track when customer manually removes a gift line item
        Event::on(
            Order::class,
            Order::EVENT_AFTER_REMOVE_LINE_ITEM,
            [$this, 'handleLineItemRemoved']
        );

        // Apply gift rules after order is saved (and recalculated)
        Event::on(
            Order::class,
            \craft\base\Element::EVENT_AFTER_SAVE,
            function (Event $event) {
                /** @var Order $order */
                $order = $event->sender;
                $this->applyGiftRules($order);
            }
        );

        // Override purchasable availability for gift products when enabled on the rule
        Event::on(
            Purchasables::class,
            Purchasables::EVENT_PURCHASABLE_AVAILABLE,
            [$this, 'handlePurchasableAvailable']
        );
    }

    /**
     * Allow gift purchasables to be added to cart even when "Available for purchase" is off,
     * if the corresponding gift rule has overridePurchasableAvailability enabled.
     */
    public function handlePurchasableAvailable(PurchasableAvailableEvent $event)
    {
        if ($event->isAvailable) {
            return;
        }

        $purchasableId = $event->purchasable->getId();
        $overriddenIds = $this->_getOverriddenPurchasableIds();

        if (!in_array($purchasableId, $overriddenIds)) {
            return;
        }

        // Allow during plugin-initiated add
        if ($this->_isAddingGift) {
            $event->isAvailable = true;
            return;
        }

        // Allow during order recalculation when the order already contains
        // this purchasable as a gift line item (otherwise Commerce removes it
        // on refresh because it considers it unavailable).
        $order = $event->order ?? null;
        if ($order && $this->_orderHasGiftLineItemForPurchasable($order, $purchasableId)) {
            $event->isAvailable = true;
        }
    }

    /**
     * Check if an order already contains a gift line item for a given purchasable.
     */
    private function _orderHasGiftLineItemForPurchasable(Order $order, int $purchasableId): bool
    {
        foreach ($order->getLineItems() as $lineItem) {
            $options = $lineItem->getOptions();
            if (!empty($options['__giftWithPurchase']) && (int)$lineItem->purchasableId === $purchasableId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get purchasable IDs that have overridePurchasableAvailability enabled
     * on at least one active gift rule.
     *
     * @return int[]
     */
    private function _getOverriddenPurchasableIds(): array
    {
        if ($this->_overriddenPurchasableIds !== null) {
            return $this->_overriddenPurchasableIds;
        }

        $this->_overriddenPurchasableIds = [];

        $rules = GiftWithPurchase::$plugin->getGiftRules()->getAllEnabledGiftRules();
        foreach ($rules as $rule) {
            if ($rule->overridePurchasableAvailability && $rule->giftPurchasableId) {
                $this->_overriddenPurchasableIds[] = (int)$rule->giftPurchasableId;
            }
        }

        $this->_overriddenPurchasableIds = array_unique($this->_overriddenPurchasableIds);

        return $this->_overriddenPurchasableIds;
    }

    /**
     * Main logic: evaluate all gift rules against order and add/remove gift line items.
     */
    public function applyGiftRules(Order $order)
    {
        // Recursion guard — adding a gift triggers another save
        if ($this->_isApplyingGifts) {
            return;
        }

        // Skip completed orders
        if ($order->isCompleted) {
            return;
        }

        // Skip console requests and queue jobs
        try {
            if (Craft::$app->getRequest()->getIsConsoleRequest()) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        $this->_isApplyingGifts = true;

        try {
            $rules = GiftWithPurchase::$plugin->getGiftRules()->getAllEnabledGiftRules();
            $lineItems = $order->getLineItems();
            $orderChanged = false;

            // Calculate non-gift subtotal
            $nonGiftSubtotal = $this->_calculateNonGiftSubtotal($lineItems);

            foreach ($rules as $rule) {
                $giftLineItem = $this->_findGiftLineItem($lineItems, $rule);
                $conditionsMet = $this->_matchesConditions($rule, $order, $nonGiftSubtotal, $lineItems);

                if ($conditionsMet && !$giftLineItem) {
                    // Conditions met, gift not in cart — should we add?
                    if (!$rule->autoAdd) {
                        continue;
                    }

                    // Check if user removed this gift (and rule says don't re-add)
                    if (!$rule->reAddOnRemoval && $this->_wasGiftRemoved($order, $rule->id)) {
                        continue;
                    }

                    // Add gift line item
                    $this->_addGiftLineItem($order, $rule);
                    $orderChanged = true;
                } elseif (!$conditionsMet && $giftLineItem) {
                    // Conditions not met, but gift is in cart — remove it
                    $order->removeLineItem($giftLineItem);
                    $orderChanged = true;
                }
            }

            if ($orderChanged) {
                // Re-save the order to persist changes
                Craft::$app->getElements()->saveElement($order, false);
            }
        } finally {
            $this->_isApplyingGifts = false;
        }
    }

    /**
     * Track when a gift line item is manually removed by the customer.
     */
    public function handleLineItemRemoved($event)
    {
        $lineItem = $event->lineItem;
        $options = $lineItem->getOptions();

        if (!empty($options['__giftWithPurchase']) && !empty($options['__giftRuleId'])) {
            /** @var Order $order */
            $order = $event->sender;
            $this->_trackGiftRemoval($order, (int)$options['__giftRuleId']);
        }
    }

    /**
     * Clear removed gifts session data when order is completed.
     */
    public function handleOrderComplete($event)
    {
        /** @var Order $order */
        $order = $event->sender;
        $this->_clearRemovedGifts($order);
    }

    /**
     * Check if all conditions for a gift rule are met.
     */
    private function _matchesConditions(GiftRule $rule, Order $order, float $nonGiftSubtotal, array $lineItems): bool
    {
        // Date validity
        $now = new \DateTime();
        if ($rule->dateFrom && $now < $rule->dateFrom) {
            return false;
        }
        if ($rule->dateTo && $now > $rule->dateTo) {
            return false;
        }

        // Subtotal conditions (using non-gift subtotal)
        if ($rule->minSubtotal !== null && $rule->minSubtotal !== '' && $nonGiftSubtotal < (float)$rule->minSubtotal) {
            return false;
        }
        if ($rule->maxSubtotal !== null && $rule->maxSubtotal !== '' && $nonGiftSubtotal > (float)$rule->maxSubtotal) {
            return false;
        }

        // Required purchasables in cart
        if (!$rule->allPurchasables) {
            $requiredIds = $rule->getPurchasableIds();
            if (!empty($requiredIds)) {
                $cartPurchasableIds = array_map(function ($li) {
                    return $li->purchasableId;
                }, $this->_getNonGiftLineItems($lineItems));

                foreach ($requiredIds as $requiredId) {
                    if (!in_array($requiredId, $cartPurchasableIds)) {
                        return false;
                    }
                }
            }
        }

        // Required categories
        if (!$rule->allCategories) {
            $requiredCategoryIds = $rule->getCategoryIds();
            if (!empty($requiredCategoryIds)) {
                if (!$this->_cartHasItemsInCategories($lineItems, $requiredCategoryIds)) {
                    return false;
                }
            }
        }

        // User group condition
        $userGroupIds = $rule->getUserGroupIds();
        if (!empty($userGroupIds)) {
            $currentUser = Craft::$app->getUser()->getIdentity();
            if (!$currentUser) {
                return false;
            }
            $userGroups = Craft::$app->getUserGroups()->getGroupsByUserId($currentUser->id);
            $userGroupIdList = array_map(function ($g) { return $g->id; }, $userGroups);
            $hasMatch = !empty(array_intersect($userGroupIds, $userGroupIdList));
            if (!$hasMatch) {
                return false;
            }
        }

        return true;
    }

    /**
     * Find an existing gift line item for a rule.
     */
    private function _findGiftLineItem(array $lineItems, GiftRule $rule)
    {
        foreach ($lineItems as $lineItem) {
            $options = $lineItem->getOptions();
            if (!empty($options['__giftWithPurchase']) &&
                isset($options['__giftRuleId']) &&
                (int)$options['__giftRuleId'] === $rule->id) {
                return $lineItem;
            }
        }
        return null;
    }

    /**
     * Add a gift line item to the order.
     * The line item keeps its original purchasable price — the discount is
     * applied by GiftDiscountAdjuster during order recalculation.
     */
    private function _addGiftLineItem(Order $order, GiftRule $rule)
    {
        $lineItemService = Commerce::getInstance()->getLineItems();

        $options = [
            '__giftWithPurchase' => true,
            '__giftRuleId' => $rule->id,
        ];

        // Set flag so the EVENT_PURCHASABLE_AVAILABLE handler knows this is
        // a plugin-initiated add, not a customer adding the item directly.
        $this->_isAddingGift = true;
        try {
            $lineItem = $lineItemService->createLineItem(
                $order->id,
                $rule->giftPurchasableId,
                $options,
                $rule->giftQty,
                $rule->note ?? '',
                $order
            );

            $order->addLineItem($lineItem);
        } finally {
            $this->_isAddingGift = false;
        }
    }

    /**
     * Calculate subtotal excluding gift line items.
     */
    private function _calculateNonGiftSubtotal(array $lineItems): float
    {
        $subtotal = 0;
        foreach ($lineItems as $lineItem) {
            $options = $lineItem->getOptions();
            if (empty($options['__giftWithPurchase'])) {
                $subtotal += $lineItem->getSubtotal();
            }
        }
        return $subtotal;
    }

    /**
     * Get non-gift line items.
     */
    private function _getNonGiftLineItems(array $lineItems): array
    {
        return array_filter($lineItems, function ($lineItem) {
            $options = $lineItem->getOptions();
            return empty($options['__giftWithPurchase']);
        });
    }

    /**
     * Check if cart has items related to any of the required categories.
     */
    private function _cartHasItemsInCategories(array $lineItems, array $requiredCategoryIds): bool
    {
        $nonGiftItems = $this->_getNonGiftLineItems($lineItems);

        foreach ($nonGiftItems as $lineItem) {
            $purchasable = $lineItem->getPurchasable();
            if (!$purchasable) {
                continue;
            }

            // Get the product element (parent of variant)
            $element = $purchasable;
            if (method_exists($purchasable, 'getProduct')) {
                $element = $purchasable->getProduct();
            }

            if (!$element) {
                continue;
            }

            // Check if element is related to any of the required categories
            $relatedCategoryIds = Category::find()
                ->relatedTo($element)
                ->ids();

            if (!empty(array_intersect($requiredCategoryIds, $relatedCategoryIds))) {
                return true;
            }
        }

        return false;
    }

    // --- Session tracking for manual gift removals ---

    private function _getSessionKey(Order $order): string
    {
        $identifier = $order->number ?? $order->id ?? 'temp';
        return 'gwp_removedGifts_' . $identifier;
    }

    private function _trackGiftRemoval(Order $order, int $ruleId)
    {
        $session = Craft::$app->getSession();
        $key = $this->_getSessionKey($order);
        $removed = $session->get($key, []);
        if (!in_array($ruleId, $removed)) {
            $removed[] = $ruleId;
        }
        $session->set($key, $removed);
    }

    private function _wasGiftRemoved(Order $order, int $ruleId): bool
    {
        $session = Craft::$app->getSession();
        $key = $this->_getSessionKey($order);
        $removed = $session->get($key, []);
        return in_array($ruleId, $removed);
    }

    private function _clearRemovedGifts(Order $order)
    {
        $session = Craft::$app->getSession();
        $key = $this->_getSessionKey($order);
        $session->remove($key);
    }
}
