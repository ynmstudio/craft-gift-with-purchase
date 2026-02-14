<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Gift with Purchase icon"></p>

<h1 align="center">Gift with Purchase for Craft Commerce</h1>

Automatically add gift products to shopping carts when configurable conditions are met. Create promotional rules that trigger free or discounted gift items based on cart contents, purchase amounts, dates, customer groups, and product categories.

## Requirements

- Craft CMS 5.0+
- Craft Commerce 5.0+
- PHP 8.2+

> **Note:** For Craft 3 / Commerce 3 support, use version `1.x`.

## Installation

```bash
composer require ynmstudio/craft-gift-with-purchase
./craft plugin/install gift-with-purchase
```

Or install from the [Craft Plugin Store](https://plugins.craftcms.com) by searching for "Gift with Purchase".

## Features

- **Flexible gift rules** — Create rules that automatically add gift products to the cart when conditions are met and remove them when conditions are no longer satisfied.
- **Condition-based triggers** — Cart subtotal (min/max), specific purchasables, product categories, date ranges, and user groups.
- **Price override** — Set a custom price for the gift item (free, discounted, or any fixed amount).
- **Line item note** — Optionally attach a note to the gift line item (e.g. "Free gift with your order!").
- **Auto-add & re-add** — Gifts are automatically added to the cart. Optionally re-add the gift if a customer manually removes it.
- **User group targeting** — Restrict gift rules to specific user groups (Craft Pro).
- **Date scheduling** — Schedule rules for time-limited promotions with start/end dates.
- **Priority ordering** — Drag-and-drop reordering of multiple gift rules.
- **Bulk actions** — Enable, disable, and delete rules in bulk from the control panel.

## Usage

1. Navigate to **Gift with Purchase** in the control panel sidebar.
2. Click **New gift rule**.
3. Configure the rule across three tabs:

### Details

| Field | Description |
|---|---|
| **Enabled** | Toggle the rule on or off. |
| **Name** | Internal name for the rule. |
| **Note** | Optional note added to the gift line item in the cart. |
| **Gift Product** | The purchasable variant to add as a gift. |
| **Gift Quantity** | How many of the gift item to add (default: 1). |
| **Gift Price** | Override price for the gift (default: 0 = free). |

### Behavior

| Field | Description |
|---|---|
| **Auto-add** | Automatically add the gift when conditions are met. |
| **Re-add on removal** | Re-add the gift if the customer removes it from the cart. |

### Conditions

| Field | Description |
|---|---|
| **Start / End Date** | Limit the rule to a specific date range. |
| **Min / Max Subtotal** | Require a cart subtotal within a range (gift items excluded from calculation). |
| **Purchasables** | Require specific products to be in the cart. |
| **Categories** | Require products from specific categories to be in the cart. |
| **User Groups** | Restrict the rule to specific user groups (Craft Pro). |

## How It Works

When a cart is updated, the plugin evaluates all enabled gift rules. If a rule's conditions are met, the configured gift product is added as a line item with the specified price and optional note. If conditions are no longer met, the gift is automatically removed.

Gift line items are internally marked with metadata (`__giftWithPurchase`, `__giftRuleId`) so they can be distinguished from regular purchases. The gift price is maintained through Commerce's price recalculation via the `EVENT_POPULATE_LINE_ITEM` event.

## Events

```php
use ynmstudio\giftwithpurchase\services\GiftRules;
use ynmstudio\giftwithpurchase\events\GiftRuleEvent;

// Before a gift rule is saved
Event::on(GiftRules::class, GiftRules::EVENT_BEFORE_SAVE_GIFT_RULE, function (GiftRuleEvent $event) {
    $rule = $event->giftRule;
    $isNew = $event->isNew;
});

// After a gift rule is saved
Event::on(GiftRules::class, GiftRules::EVENT_AFTER_SAVE_GIFT_RULE, function (GiftRuleEvent $event) {
    // ...
});

// After a gift rule is deleted
Event::on(GiftRules::class, GiftRules::EVENT_AFTER_DELETE_GIFT_RULE, function (GiftRuleEvent $event) {
    // ...
});
```

## Support

For support, please open an issue on [GitHub](https://github.com/ynmstudio/craft-gift-with-purchase/issues).
