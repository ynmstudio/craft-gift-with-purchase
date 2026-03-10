<?php

namespace ynmstudio\giftwithpurchase\migrations;

use craft\db\Migration;
use ynmstudio\giftwithpurchase\db\Table;

class m260310_000000_add_gift_value_column extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::GIFT_RULES, 'giftValue')) {
            $this->addColumn(Table::GIFT_RULES, 'giftValue', $this->decimal(14, 4)->after('giftPrice'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        if ($this->db->columnExists(Table::GIFT_RULES, 'giftValue')) {
            $this->dropColumn(Table::GIFT_RULES, 'giftValue');
        }

        return true;
    }
}
