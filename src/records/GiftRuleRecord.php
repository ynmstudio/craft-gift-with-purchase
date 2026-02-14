<?php

namespace ynmstudio\giftwithpurchase\records;

use craft\db\ActiveRecord;
use ynmstudio\giftwithpurchase\db\Table;

/**
 * @property int $id
 * @property string $name
 * @property string|null $note
 * @property int $giftPurchasableId
 * @property int $giftQty
 * @property float $giftPrice
 * @property bool $enabled
 * @property \DateTime|null $dateFrom
 * @property \DateTime|null $dateTo
 * @property float|null $minSubtotal
 * @property float|null $maxSubtotal
 * @property bool $allCategories
 * @property bool $allPurchasables
 * @property bool $autoAdd
 * @property bool $reAddOnRemoval
 * @property int $sortOrder
 */
class GiftRuleRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::GIFT_RULES;
    }
}
