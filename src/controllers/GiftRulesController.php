<?php

namespace ynmstudio\giftwithpurchase\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\elements\Category;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\HttpException;
use yii\web\Response;

use ynmstudio\giftwithpurchase\GiftWithPurchase;
use ynmstudio\giftwithpurchase\models\GiftRule;

class GiftRulesController extends Controller
{
    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();
        $this->requirePermission('accessPlugin-gift-with-purchase');
    }

    /**
     * Gift rules index
     */
    public function actionIndex(): Response
    {
        $giftRules = GiftWithPurchase::getInstance()->getGiftRules()->getAllGiftRules();
        return $this->renderTemplate('gift-with-purchase/gift-rules/index', compact('giftRules'));
    }

    /**
     * Gift rule edit form
     */
    public function actionEdit(?int $ruleId = null, ?GiftRule $giftRule = null): Response
    {
        $variables = compact('ruleId', 'giftRule');
        $variables['isNewRule'] = false;

        if (!$variables['giftRule']) {
            if ($variables['ruleId']) {
                $variables['giftRule'] = GiftWithPurchase::getInstance()->getGiftRules()->getGiftRuleById($variables['ruleId']);
                if (!$variables['giftRule']) {
                    throw new HttpException(404);
                }
            } else {
                $variables['giftRule'] = new GiftRule();
                $variables['isNewRule'] = true;
            }
        }

        $this->_populateVariables($variables);

        return $this->renderTemplate('gift-with-purchase/gift-rules/_edit', $variables);
    }

    /**
     * Save a gift rule
     */
    public function actionSave()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $giftRule = new GiftRule();

        $giftRule->id = $request->getBodyParam('id');
        $giftRule->name = $request->getBodyParam('name');
        $giftRule->note = $request->getBodyParam('note') ?: null;
        $giftRule->enabled = (bool)$request->getBodyParam('enabled');
        $giftRule->giftQty = (int)($request->getBodyParam('giftQty') ?: 1);
        $giftRule->giftPrice = (float)($request->getBodyParam('giftPrice') ?: 0);
        $giftRule->autoAdd = (bool)$request->getBodyParam('autoAdd');
        $giftRule->reAddOnRemoval = (bool)$request->getBodyParam('reAddOnRemoval');

        // Gift purchasable (element select returns array)
        $giftPurchasableIds = $request->getBodyParam('giftPurchasableId');
        if (is_array($giftPurchasableIds) && !empty($giftPurchasableIds)) {
            $giftRule->giftPurchasableId = (int)reset($giftPurchasableIds);
        } else {
            $giftRule->giftPurchasableId = $giftPurchasableIds ? (int)$giftPurchasableIds : null;
        }

        // Dates
        $dateFrom = $request->getBodyParam('dateFrom');
        if ($dateFrom) {
            $giftRule->dateFrom = DateTimeHelper::toDateTime($dateFrom) ?: null;
        }
        $dateTo = $request->getBodyParam('dateTo');
        if ($dateTo) {
            $giftRule->dateTo = DateTimeHelper::toDateTime($dateTo) ?: null;
        }

        // Subtotals
        $minSubtotal = $request->getBodyParam('minSubtotal');
        $giftRule->minSubtotal = ($minSubtotal !== null && $minSubtotal !== '') ? (float)$minSubtotal : null;
        $maxSubtotal = $request->getBodyParam('maxSubtotal');
        $giftRule->maxSubtotal = ($maxSubtotal !== null && $maxSubtotal !== '') ? (float)$maxSubtotal : null;

        // Purchasable conditions
        if ($giftRule->allPurchasables = (bool)$request->getBodyParam('allPurchasables')) {
            $giftRule->setPurchasableIds([]);
        } else {
            $purchasables = [];
            $purchasableGroups = $request->getBodyParam('purchasables') ?: [];
            foreach ($purchasableGroups as $group) {
                if (is_array($group)) {
                    array_push($purchasables, ...$group);
                }
            }
            $giftRule->setPurchasableIds(array_unique($purchasables));
        }

        // Category conditions
        if ($giftRule->allCategories = (bool)$request->getBodyParam('allCategories')) {
            $giftRule->setCategoryIds([]);
        } else {
            $categories = $request->getBodyParam('categories', []);
            if (!$categories) {
                $categories = [];
            }
            $giftRule->setCategoryIds($categories);
        }

        // User groups
        $groups = $request->getBodyParam('groups', []);
        if (!$groups) {
            $groups = [];
        }
        $giftRule->setUserGroupIds($groups);

        // Save
        if (GiftWithPurchase::getInstance()->getGiftRules()->saveGiftRule($giftRule)) {
            $this->setSuccessFlash(Craft::t('gift-with-purchase', 'Gift rule saved.'));
            return $this->redirectToPostedUrl($giftRule);
        }

        $this->setFailFlash(Craft::t('gift-with-purchase', 'Couldn\'t save gift rule.'));

        Craft::$app->getUrlManager()->setRouteParams([
            'giftRule' => $giftRule,
        ]);

        return null;
    }

    /**
     * Delete gift rule(s)
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $id = Craft::$app->getRequest()->getBodyParam('id');
        $ids = Craft::$app->getRequest()->getBodyParam('ids');

        if ((!$id && empty($ids)) || ($id && !empty($ids))) {
            throw new BadRequestHttpException('id or ids must be specified.');
        }

        if ($id) {
            $this->requireAcceptsJson();
            $ids = [$id];
        }

        foreach ($ids as $id) {
            GiftWithPurchase::getInstance()->getGiftRules()->deleteGiftRuleById($id);
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        $this->setSuccessFlash(Craft::t('gift-with-purchase', 'Gift rule deleted.'));
        return $this->redirect($this->request->getReferrer());
    }

    /**
     * Reorder gift rules
     */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $ids = Json::decode(Craft::$app->getRequest()->getRequiredBodyParam('ids'));

        if (GiftWithPurchase::getInstance()->getGiftRules()->reorderGiftRules($ids)) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson(['error' => Craft::t('gift-with-purchase', 'Couldn\'t reorder gift rules.')]);
    }

    /**
     * Update status of gift rules
     */
    public function actionUpdateStatus()
    {
        $this->requirePostRequest();

        $ids = Craft::$app->getRequest()->getRequiredBodyParam('ids');
        $status = Craft::$app->getRequest()->getRequiredBodyParam('status');

        if (empty($ids)) {
            $this->setFailFlash(Craft::t('gift-with-purchase', 'Couldn\'t update status.'));
            return;
        }

        GiftWithPurchase::getInstance()->getGiftRules()->updateStatusByIds($ids, $status === 'enabled');

        $this->setSuccessFlash(Craft::t('gift-with-purchase', 'Gift rules updated.'));
    }

    private function _populateVariables(array &$variables)
    {
        $giftRule = $variables['giftRule'];

        if ($giftRule->id) {
            $variables['title'] = $giftRule->note ?: $giftRule->name;
        } else {
            $variables['title'] = Craft::t('gift-with-purchase', 'New gift rule');
        }

        // User groups
        if (Craft::$app->getEdition() == Craft::Pro) {
            $groups = Craft::$app->getUserGroups()->getAllGroups();
            $variables['groups'] = ArrayHelper::map($groups, 'id', 'name');
        } else {
            $variables['groups'] = [];
        }

        // Gift purchasable element
        $variables['giftPurchasable'] = null;
        if ($giftRule->giftPurchasableId) {
            $variables['giftPurchasable'] = Craft::$app->getElements()->getElementById($giftRule->giftPurchasableId);
        }

        // Purchasable types for element selects
        $commerce = Commerce::getInstance();
        $purchasableTypes = $commerce->getPurchasables()->getAllPurchasableElementTypes();
        $variables['purchasableTypes'] = [];
        foreach ($purchasableTypes as $purchasableType) {
            $variables['purchasableTypes'][] = [
                'name' => $purchasableType::displayName(),
                'elementType' => $purchasableType,
            ];
        }

        // Existing purchasable selections
        $variables['purchasables'] = [];
        if (!$variables['isNewRule']) {
            $purchasableIds = $giftRule->getPurchasableIds();
            foreach ($purchasableIds as $purchasableId) {
                $element = Craft::$app->getElements()->getElementById($purchasableId);
                if ($element) {
                    $type = get_class($element);
                    if (!isset($variables['purchasables'][$type])) {
                        $variables['purchasables'][$type] = [];
                    }
                    $variables['purchasables'][$type][] = $element;
                }
            }
        }

        // Categories
        $variables['categoryElementType'] = Category::class;
        $variables['categories'] = [];
        $categoryIds = $giftRule->getCategoryIds();
        foreach ($categoryIds as $categoryId) {
            $cat = Craft::$app->getElements()->getElementById((int)$categoryId);
            if ($cat) {
                $variables['categories'][] = $cat;
            }
        }
    }
}
