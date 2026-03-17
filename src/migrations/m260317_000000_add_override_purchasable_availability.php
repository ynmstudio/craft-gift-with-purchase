<?php

namespace ynmstudio\giftwithpurchase\migrations;

use craft\db\Migration;
use ynmstudio\giftwithpurchase\db\Table;

class m260317_000000_add_override_purchasable_availability extends Migration
{
    public function safeUp(): bool
    {
        $this->addColumn(
            Table::GIFT_RULES,
            'overridePurchasableAvailability',
            $this->boolean()->notNull()->defaultValue(false)->after('reAddOnRemoval')
        );

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(Table::GIFT_RULES, 'overridePurchasableAvailability');

        return true;
    }
}
