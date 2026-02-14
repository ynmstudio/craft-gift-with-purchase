<?php

namespace ynmstudio\giftwithpurchase\records;

use craft\db\ActiveRecord;
use ynmstudio\giftwithpurchase\db\Table;

/**
 * @property int $id
 * @property int $giftRuleId
 * @property int $categoryId
 */
class GiftRuleCategoryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::GIFT_RULE_CATEGORIES;
    }
}
