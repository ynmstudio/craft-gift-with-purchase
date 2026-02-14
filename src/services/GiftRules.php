<?php

namespace ynmstudio\giftwithpurchase\services;

use Craft;
use craft\db\Query;
use yii\base\Component;
use yii\base\Exception;

use ynmstudio\giftwithpurchase\db\Table;
use ynmstudio\giftwithpurchase\events\GiftRuleEvent;
use ynmstudio\giftwithpurchase\models\GiftRule;
use ynmstudio\giftwithpurchase\records\GiftRuleRecord;
use ynmstudio\giftwithpurchase\records\GiftRuleCategoryRecord;
use ynmstudio\giftwithpurchase\records\GiftRulePurchasableRecord;
use ynmstudio\giftwithpurchase\records\GiftRuleUserGroupRecord;

class GiftRules extends Component
{
    const EVENT_BEFORE_SAVE_GIFT_RULE = 'beforeSaveGiftRule';
    const EVENT_AFTER_SAVE_GIFT_RULE = 'afterSaveGiftRule';
    const EVENT_AFTER_DELETE_GIFT_RULE = 'afterDeleteGiftRule';

    /** @var GiftRule[]|null */
    private $_allGiftRules;

    /**
     * @return GiftRule[]
     */
    public function getAllGiftRules(): array
    {
        if ($this->_allGiftRules !== null) {
            return $this->_allGiftRules;
        }

        $rows = $this->_createGiftRuleQuery()
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        $this->_allGiftRules = [];
        foreach ($rows as $row) {
            $this->_allGiftRules[] = new GiftRule($row);
        }

        return $this->_allGiftRules;
    }

    /**
     * @return GiftRule[]
     */
    public function getAllEnabledGiftRules(): array
    {
        return array_filter($this->getAllGiftRules(), function (GiftRule $rule) {
            return $rule->enabled;
        });
    }

    /**
     * @param int $id
     * @return GiftRule|null
     */
    public function getGiftRuleById(int $id): ?GiftRule
    {
        $row = $this->_createGiftRuleQuery()
            ->where(['id' => $id])
            ->one();

        if (!$row) {
            return null;
        }

        return new GiftRule($row);
    }

    /**
     * @param GiftRule $model
     * @param bool $runValidation
     * @return bool
     * @throws \Throwable
     */
    public function saveGiftRule(GiftRule $model, bool $runValidation = true): bool
    {
        $isNew = !$model->id;

        if ($model->id) {
            $record = GiftRuleRecord::findOne($model->id);
            if (!$record) {
                throw new Exception("No gift rule exists with the ID \"{$model->id}\"");
            }
        } else {
            $record = new GiftRuleRecord();
        }

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_GIFT_RULE)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_GIFT_RULE, new GiftRuleEvent([
                'giftRule' => $model,
                'isNew' => $isNew,
            ]));
        }

        if ($runValidation && !$model->validate()) {
            Craft::info('Gift rule not saved due to validation error.', __METHOD__);
            return false;
        }

        $record->name = $model->name;
        $record->note = $model->note;
        $record->giftPurchasableId = $model->giftPurchasableId;
        $record->giftQty = $model->giftQty;
        $record->giftPrice = $model->giftPrice;
        $record->enabled = $model->enabled;
        $record->dateFrom = $model->dateFrom;
        $record->dateTo = $model->dateTo;
        $record->minSubtotal = $model->minSubtotal;
        $record->maxSubtotal = $model->maxSubtotal;
        $record->autoAdd = $model->autoAdd;
        $record->reAddOnRemoval = $model->reAddOnRemoval;
        $record->sortOrder = $record->sortOrder ?: 999;

        if ($record->allCategories = $model->allCategories) {
            $model->setCategoryIds([]);
        }
        if ($record->allPurchasables = $model->allPurchasables) {
            $model->setPurchasableIds([]);
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $record->save(false);
            $model->id = $record->id;

            // Delete and re-save pivot records
            GiftRuleUserGroupRecord::deleteAll(['giftRuleId' => $model->id]);
            GiftRulePurchasableRecord::deleteAll(['giftRuleId' => $model->id]);
            GiftRuleCategoryRecord::deleteAll(['giftRuleId' => $model->id]);

            foreach ($model->getUserGroupIds() as $groupId) {
                $relation = new GiftRuleUserGroupRecord();
                $relation->userGroupId = $groupId;
                $relation->giftRuleId = $model->id;
                $relation->save(false);
            }

            foreach ($model->getCategoryIds() as $categoryId) {
                $relation = new GiftRuleCategoryRecord();
                $relation->categoryId = $categoryId;
                $relation->giftRuleId = $model->id;
                $relation->save(false);
            }

            foreach ($model->getPurchasableIds() as $purchasableId) {
                $element = Craft::$app->getElements()->getElementById($purchasableId);
                if (!$element) {
                    Craft::warning("Purchasable {$purchasableId} not found, skipping.", __METHOD__);
                    continue;
                }
                $relation = new GiftRulePurchasableRecord();
                $relation->purchasableType = get_class($element);
                $relation->purchasableId = $purchasableId;
                $relation->giftRuleId = $model->id;
                $relation->save(false);
            }

            $transaction->commit();

            if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_GIFT_RULE)) {
                $this->trigger(self::EVENT_AFTER_SAVE_GIFT_RULE, new GiftRuleEvent([
                    'giftRule' => $model,
                    'isNew' => $isNew,
                ]));
            }

            $this->_allGiftRules = null;

            return true;
        } catch (\Exception $e) {
            $transaction->rollBack();
            throw $e;
        }
    }

    /**
     * @param int $id
     * @return bool
     */
    public function deleteGiftRuleById(int $id): bool
    {
        $record = GiftRuleRecord::findOne($id);

        if (!$record) {
            return false;
        }

        $giftRule = $this->getGiftRuleById($id);
        $result = (bool)$record->delete();

        if ($result && $this->hasEventHandlers(self::EVENT_AFTER_DELETE_GIFT_RULE)) {
            $this->trigger(self::EVENT_AFTER_DELETE_GIFT_RULE, new GiftRuleEvent([
                'giftRule' => $giftRule,
                'isNew' => false,
            ]));
        }

        $this->_allGiftRules = null;

        return $result;
    }

    /**
     * @param array $ids
     * @return bool
     */
    public function reorderGiftRules(array $ids): bool
    {
        foreach ($ids as $sortOrder => $id) {
            Craft::$app->getDb()->createCommand()
                ->update(Table::GIFT_RULES, ['sortOrder' => $sortOrder + 1], ['id' => $id])
                ->execute();
        }

        $this->_allGiftRules = null;

        return true;
    }

    /**
     * @param array $ids
     * @param bool $enabled
     * @return bool
     */
    public function updateStatusByIds(array $ids, bool $enabled): bool
    {
        Craft::$app->getDb()->createCommand()
            ->update(Table::GIFT_RULES, ['enabled' => $enabled], ['id' => $ids])
            ->execute();

        $this->_allGiftRules = null;

        return true;
    }

    private function _createGiftRuleQuery(): Query
    {
        return (new Query())
            ->select([
                'id',
                'name',
                'note',
                'giftPurchasableId',
                'giftQty',
                'giftPrice',
                'enabled',
                'dateFrom',
                'dateTo',
                'minSubtotal',
                'maxSubtotal',
                'allCategories',
                'allPurchasables',
                'autoAdd',
                'reAddOnRemoval',
                'sortOrder',
                'dateCreated',
                'dateUpdated',
                'uid',
            ])
            ->from(Table::GIFT_RULES);
    }
}
