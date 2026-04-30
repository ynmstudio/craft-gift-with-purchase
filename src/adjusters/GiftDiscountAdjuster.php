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

            $rule = GiftWithPurchase::$plugin->getGiftRules()->getGiftRuleById((int)$options['__giftRuleId']);
            if (!$rule) {
                continue;
            }

            $discountPercent = (float)$rule->giftDiscountPercent;
            if ($discountPercent <= 0) {
                continue;
            }

            if ($discountPercent >= 100) {
                // For a full 100% gift, neutralize the line's tax adjustments so the
                // line records zero VAT — otherwise Commerce keeps tax collected on a
                // free item, producing incorrect tax-detail rows on invoices.
                foreach ($lineItem->getAdjustments() as $existing) {
                    if ($existing->type !== 'tax' || (float)$existing->amount === 0.0) {
                        continue;
                    }

                    $counter = new OrderAdjustment();
                    $counter->type = 'tax';
                    $counter->name = 'Gift Tax Exemption';
                    $counter->description = $rule->name;
                    $counter->setLineItem($lineItem);
                    $counter->setOrder($order);
                    $counter->amount = -$existing->amount;
                    $counter->included = (bool)$existing->included;
                    $counter->sourceSnapshot = array_merge(
                        $existing->getSourceSnapshot() ?: [],
                        [
                            'giftRuleId' => $rule->id,
                            'giftTaxExemption' => true,
                        ]
                    );

                    $adjustments[] = $counter;
                }

                // Size the discount so subtotal + total discount = 0, which makes
                // both the line total and the line's taxable subtotal land on 0.
                $discountAmount = -($lineItem->getSubtotal() + $lineItem->getDiscount());
            } else {
                // Partial gift: keep the percentage-of-current-total behavior so tax
                // still applies on the portion the customer actually pays.
                $currentTotal = $lineItem->getSubtotal() + $lineItem->getAdjustmentsTotal();
                $discountAmount = -($currentTotal * ($discountPercent / 100));
            }

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
