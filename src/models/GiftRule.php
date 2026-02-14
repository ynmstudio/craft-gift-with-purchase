<?php

namespace ynmstudio\giftwithpurchase\models;

use Craft;
use craft\base\Model;
use craft\helpers\UrlHelper;
use craft\db\Query;
use ynmstudio\giftwithpurchase\db\Table;

class GiftRule extends Model
{
    /** @var int|null */
    public $id;

    /** @var string */
    public $name;

    /** @var string|null */
    public $note;

    /** @var int */
    public $giftPurchasableId;

    /** @var int */
    public $giftQty = 1;

    /** @var float */
    public $giftPrice = 0;

    /** @var bool */
    public $enabled = true;

    /** @var \DateTime|null */
    public $dateFrom;

    /** @var \DateTime|null */
    public $dateTo;

    /** @var float|null */
    public $minSubtotal;

    /** @var float|null */
    public $maxSubtotal;

    /** @var bool */
    public $allCategories = true;

    /** @var bool */
    public $allPurchasables = true;

    /** @var bool */
    public $autoAdd = true;

    /** @var bool */
    public $reAddOnRemoval = false;

    /** @var int */
    public $sortOrder = 999;

    /** @var \DateTime|null */
    public $dateCreated;

    /** @var \DateTime|null */
    public $dateUpdated;

    /** @var string|null */
    public $uid;

    /** @var int[]|null */
    private $_categoryIds;

    /** @var int[]|null */
    private $_purchasableIds;

    /** @var int[]|null */
    private $_userGroupIds;

    /**
     * @inheritdoc
     */
    public function datetimeAttributes(): array
    {
        $attributes = parent::datetimeAttributes();
        $attributes[] = 'dateFrom';
        $attributes[] = 'dateTo';
        return $attributes;
    }

    /**
     * @return string|false
     */
    public function getCpEditUrl()
    {
        return UrlHelper::cpUrl('gift-with-purchase/rules/' . $this->id);
    }

    /**
     * @return int[]
     */
    public function getCategoryIds(): array
    {
        if ($this->_categoryIds === null) {
            $this->_loadCategoryRelations();
        }
        return $this->_categoryIds;
    }

    /**
     * @return int[]
     */
    public function getPurchasableIds(): array
    {
        if ($this->_purchasableIds === null) {
            $this->_loadPurchasableRelations();
        }
        return $this->_purchasableIds;
    }

    /**
     * @return int[]
     */
    public function getUserGroupIds(): array
    {
        if ($this->_userGroupIds === null) {
            $this->_loadUserGroupRelations();
        }
        return $this->_userGroupIds;
    }

    /**
     * @param int[] $categoryIds
     */
    public function setCategoryIds(array $categoryIds)
    {
        $this->_categoryIds = array_unique($categoryIds);
    }

    /**
     * @param int[] $purchasableIds
     */
    public function setPurchasableIds(array $purchasableIds)
    {
        $this->_purchasableIds = array_unique($purchasableIds);
    }

    /**
     * @param int[] $userGroupIds
     */
    public function setUserGroupIds(array $userGroupIds)
    {
        $this->_userGroupIds = array_unique($userGroupIds);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'giftPurchasableId'], 'required'],
            [['giftQty'], 'integer', 'min' => 1],
            [['giftPrice', 'minSubtotal', 'maxSubtotal'], 'number', 'skipOnEmpty' => true],
            [['giftPurchasableId', 'sortOrder'], 'integer'],
            [['enabled', 'allCategories', 'allPurchasables', 'autoAdd', 'reAddOnRemoval'], 'boolean'],
        ];
    }

    private function _loadCategoryRelations()
    {
        if (!$this->id) {
            $this->_categoryIds = [];
            return;
        }

        $this->_categoryIds = (new Query())
            ->select(['categoryId'])
            ->from(Table::GIFT_RULE_CATEGORIES)
            ->where(['giftRuleId' => $this->id])
            ->column();
    }

    private function _loadPurchasableRelations()
    {
        if (!$this->id) {
            $this->_purchasableIds = [];
            return;
        }

        $this->_purchasableIds = (new Query())
            ->select(['purchasableId'])
            ->from(Table::GIFT_RULE_PURCHASABLES)
            ->where(['giftRuleId' => $this->id])
            ->column();
    }

    private function _loadUserGroupRelations()
    {
        if (!$this->id) {
            $this->_userGroupIds = [];
            return;
        }

        $this->_userGroupIds = (new Query())
            ->select(['userGroupId'])
            ->from(Table::GIFT_RULE_USERGROUPS)
            ->where(['giftRuleId' => $this->id])
            ->column();
    }
}
