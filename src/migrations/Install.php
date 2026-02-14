<?php

namespace ynmstudio\giftwithpurchase\migrations;

use craft\db\Migration;
use ynmstudio\giftwithpurchase\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->_createGiftRulesTable();
        $this->_createGiftRuleCategoriesTable();
        $this->_createGiftRulePurchasablesTable();
        $this->_createGiftRuleUserGroupsTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::GIFT_RULE_USERGROUPS);
        $this->dropTableIfExists(Table::GIFT_RULE_PURCHASABLES);
        $this->dropTableIfExists(Table::GIFT_RULE_CATEGORIES);
        $this->dropTableIfExists(Table::GIFT_RULES);

        return true;
    }

    private function _createGiftRulesTable()
    {
        $this->createTable(Table::GIFT_RULES, [
            'id' => $this->primaryKey(),
            'name' => $this->string(255)->notNull(),
            'note' => $this->string(255),
            'giftPurchasableId' => $this->integer()->notNull(),
            'giftQty' => $this->integer()->notNull()->defaultValue(1),
            'giftPrice' => $this->decimal(14, 4)->notNull()->defaultValue(0),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'dateFrom' => $this->dateTime(),
            'dateTo' => $this->dateTime(),
            'minSubtotal' => $this->decimal(14, 4),
            'maxSubtotal' => $this->decimal(14, 4),
            'allCategories' => $this->boolean()->notNull()->defaultValue(true),
            'allPurchasables' => $this->boolean()->notNull()->defaultValue(true),
            'autoAdd' => $this->boolean()->notNull()->defaultValue(true),
            'reAddOnRemoval' => $this->boolean()->notNull()->defaultValue(false),
            'sortOrder' => $this->integer()->notNull()->defaultValue(999),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(
            null,
            Table::GIFT_RULES,
            'giftPurchasableId',
            '{{%commerce_purchasables}}',
            'id',
            'CASCADE',
            'CASCADE'
        );
    }

    private function _createGiftRuleCategoriesTable()
    {
        $this->createTable(Table::GIFT_RULE_CATEGORIES, [
            'id' => $this->primaryKey(),
            'giftRuleId' => $this->integer()->notNull(),
            'categoryId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, Table::GIFT_RULE_CATEGORIES, 'giftRuleId', Table::GIFT_RULES, 'id', 'CASCADE');
        $this->addForeignKey(null, Table::GIFT_RULE_CATEGORIES, 'categoryId', '{{%categories}}', 'id', 'CASCADE');
        $this->createIndex(null, Table::GIFT_RULE_CATEGORIES, ['giftRuleId', 'categoryId'], true);
    }

    private function _createGiftRulePurchasablesTable()
    {
        $this->createTable(Table::GIFT_RULE_PURCHASABLES, [
            'id' => $this->primaryKey(),
            'giftRuleId' => $this->integer()->notNull(),
            'purchasableId' => $this->integer()->notNull(),
            'purchasableType' => $this->string()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, Table::GIFT_RULE_PURCHASABLES, 'giftRuleId', Table::GIFT_RULES, 'id', 'CASCADE');
        $this->addForeignKey(null, Table::GIFT_RULE_PURCHASABLES, 'purchasableId', '{{%commerce_purchasables}}', 'id', 'CASCADE');
        $this->createIndex(null, Table::GIFT_RULE_PURCHASABLES, ['giftRuleId', 'purchasableId'], true);
    }

    private function _createGiftRuleUserGroupsTable()
    {
        $this->createTable(Table::GIFT_RULE_USERGROUPS, [
            'id' => $this->primaryKey(),
            'giftRuleId' => $this->integer()->notNull(),
            'userGroupId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->addForeignKey(null, Table::GIFT_RULE_USERGROUPS, 'giftRuleId', Table::GIFT_RULES, 'id', 'CASCADE');
        $this->addForeignKey(null, Table::GIFT_RULE_USERGROUPS, 'userGroupId', '{{%usergroups}}', 'id', 'CASCADE');
        $this->createIndex(null, Table::GIFT_RULE_USERGROUPS, ['giftRuleId', 'userGroupId'], true);
    }
}
