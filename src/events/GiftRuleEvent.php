<?php

namespace ynmstudio\giftwithpurchase\events;

use yii\base\Event;
use ynmstudio\giftwithpurchase\models\GiftRule;

class GiftRuleEvent extends Event
{
    /** @var GiftRule */
    public $giftRule;

    /** @var bool */
    public $isNew = false;
}
