<?php

namespace ynmstudio\giftwithpurchase\migrations;

use craft\db\Migration;
use ynmstudio\giftwithpurchase\db\Table;

class m260312_000000_replace_gift_price_with_discount_percent extends Migration
{
    public function safeUp(): bool
    {
        // Add the new discount percent column
        if (!$this->db->columnExists(Table::GIFT_RULES, 'giftDiscountPercent')) {
            $this->addColumn(
                Table::GIFT_RULES,
                'giftDiscountPercent',
                $this->decimal(5, 2)->notNull()->defaultValue(100)->after('giftPrice')
            );

            // Migrate existing data: giftPrice of 0 = 100% discount (free)
            // For non-zero giftPrice values, we cannot reliably calculate a percentage
            // without knowing the original product price, so default to 100%
            $this->update(Table::GIFT_RULES, ['giftDiscountPercent' => 100]);
        }

        // Drop old columns
        if ($this->db->columnExists(Table::GIFT_RULES, 'giftPrice')) {
            $this->dropColumn(Table::GIFT_RULES, 'giftPrice');
        }
        if ($this->db->columnExists(Table::GIFT_RULES, 'giftValue')) {
            $this->dropColumn(Table::GIFT_RULES, 'giftValue');
        }

        return true;
    }

    public function safeDown(): bool
    {
        if (!$this->db->columnExists(Table::GIFT_RULES, 'giftPrice')) {
            $this->addColumn(
                Table::GIFT_RULES,
                'giftPrice',
                $this->decimal(14, 4)->notNull()->defaultValue(0)->after('giftQty')
            );
        }

        if (!$this->db->columnExists(Table::GIFT_RULES, 'giftValue')) {
            $this->addColumn(
                Table::GIFT_RULES,
                'giftValue',
                $this->decimal(14, 4)->after('giftPrice')
            );
        }

        if ($this->db->columnExists(Table::GIFT_RULES, 'giftDiscountPercent')) {
            $this->dropColumn(Table::GIFT_RULES, 'giftDiscountPercent');
        }

        return true;
    }
}
