<?php

namespace ynmstudio\giftwithpurchase\adjusters;

use craft\commerce\base\AdjusterInterface;
use craft\commerce\elements\Order;
use craft\commerce\models\OrderAdjustment;

use ynmstudio\giftwithpurchase\GiftWithPurchase;

class GiftDiscountAdjuster implements AdjusterInterface
{
    const ADJUSTMENT_TYPE = 'discount';

    /**
     * @inheritdoc
     */
    public function adjust(Order $order): array
    {
        $adjustments = [];
        $lineItems = $order->getLineItems();

        foreach ($lineItems as $lineItem) {
            $options = $lineItem->getOptions();

            if (empty($options['__giftWithPurchase']) || empty($options['__giftRuleId'])) {
                continue;
            }

            $rule = GiftWithPurchase::getInstance()->getGiftRules()->getGiftRuleById((int)$options['__giftRuleId']);
            if (!$rule) {
                continue;
            }

            $discountPercent = (float)$rule->giftDiscountPercent;
            if ($discountPercent <= 0) {
                continue;
            }

            // Use promotionalPrice if set, otherwise salePrice (Commerce 5 uses promotionalPrice)
            $unitPrice = $lineItem->promotionalPrice ?? $lineItem->salePrice;
            $discountAmount = -($unitPrice * $lineItem->qty * ($discountPercent / 100));

            $adjustment = new OrderAdjustment();
            $adjustment->type = self::ADJUSTMENT_TYPE;
            $adjustment->name = 'Offered';
            $adjustment->description = $rule->name;
            $adjustment->setLineItem($lineItem);
            $adjustment->setOrder($order);
            $adjustment->amount = $discountAmount;
            $adjustment->sourceSnapshot = [
                'giftRuleId' => $rule->id,
                'giftDiscountPercent' => $discountPercent,
            ];

            $adjustments[] = $adjustment;
        }

        return $adjustments;
    }
}
