<?php

namespace ynmstudio\giftwithpurchase;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\UrlHelper;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

use ynmstudio\giftwithpurchase\services\GiftRules;
use ynmstudio\giftwithpurchase\services\GiftCart;

/**
 * Gift with Purchase plugin
 *
 * @property GiftRules $giftRules
 * @property GiftCart $giftCart
 */
class GiftWithPurchase extends Plugin
{
    /**
     * @var bool
     */
    public bool $hasCpSection = true;

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    public function init(): void
    {
        parent::init();

        $this->setComponents([
            'giftRules' => GiftRules::class,
            'giftCart' => GiftCart::class,
        ]);

        $this->_registerCpRoutes();
        $this->_registerTemplateRoots();
        $this->_registerCartEventListeners();

        Craft::info('Gift with Purchase plugin initialized', __METHOD__);
    }

    /**
     * @return GiftRules
     */
    public function getGiftRules(): GiftRules
    {
        return $this->get('giftRules');
    }

    /**
     * @return GiftCart
     */
    public function getGiftCart(): GiftCart
    {
        return $this->get('giftCart');
    }

    private function _registerCpRoutes()
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['gift-with-purchase'] = 'gift-with-purchase/gift-rules/index';
                $event->rules['gift-with-purchase/rules'] = 'gift-with-purchase/gift-rules/index';
                $event->rules['gift-with-purchase/rules/new'] = 'gift-with-purchase/gift-rules/edit';
                $event->rules['gift-with-purchase/rules/<ruleId:\d+>'] = 'gift-with-purchase/gift-rules/edit';
            }
        );
    }

    private function _registerTemplateRoots()
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['gift-with-purchase'] = __DIR__ . '/templates';
            }
        );
    }

    private function _registerCartEventListeners()
    {
        $this->getGiftCart()->registerEventListeners();
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('gift-with-purchase/rules'));
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $item = parent::getCpNavItem();
        $item['label'] = Craft::t('gift-with-purchase', 'Gift with Purchase');
        $item['subnav'] = [
            'rules' => [
                'label' => Craft::t('gift-with-purchase', 'Gift Rules'),
                'url' => 'gift-with-purchase/rules',
            ],
        ];
        return $item;
    }
}
