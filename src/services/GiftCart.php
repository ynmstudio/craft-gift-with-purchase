<?php

namespace ynmstudio\giftwithpurchase\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\events\LineItemEvent;
use craft\commerce\Plugin as Commerce;
use craft\commerce\services\LineItems;
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

        // Ensure gift prices persist through Commerce recalculations
        Event::on(
            LineItems::class,
            LineItems::EVENT_POPULATE_LINE_ITEM,
            [$this, 'handlePopulateLineItem']
        );
    }

    /**
     * Ensure gift line item prices are maintained during Commerce price recalculation.
     * Commerce refreshes prices from the purchasable on every recalculation —
     * this event listener overrides them back to the gift price.
     */
    public function handlePopulateLineItem(LineItemEvent $event)
    {
        $lineItem = $event->lineItem;
        $options = $lineItem->getOptions();

        if (!empty($options['__giftWithPurchase']) && !empty($options['__giftRuleId'])) {
            $rule = GiftWithPurchase::getInstance()->getGiftRules()->getGiftRuleById((int)$options['__giftRuleId']);
            if ($rule) {
                $lineItem->price = (float)$rule->giftPrice;
                $lineItem->promotionalPrice = (float)$rule->giftPrice;
            }
        }
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
            $rules = GiftWithPurchase::getInstance()->getGiftRules()->getAllEnabledGiftRules();
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
     */
    private function _addGiftLineItem(Order $order, GiftRule $rule)
    {
        $lineItemService = Commerce::getInstance()->getLineItems();

        $options = [
            '__giftWithPurchase' => true,
            '__giftRuleId' => $rule->id,
        ];

        $lineItem = $lineItemService->createLineItem(
            $order,
            $rule->giftPurchasableId,
            $options,
            $rule->giftQty,
            $rule->note ?? ''
        );

        // Set initial price (will be maintained by EVENT_POPULATE_LINE_ITEM handler)
        $lineItem->price = (float)$rule->giftPrice;
        $lineItem->promotionalPrice = (float)$rule->giftPrice;

        $order->addLineItem($lineItem);
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
            if (method_exists($purchasable, 'getOwner')) {
                $element = $purchasable->getOwner();
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
