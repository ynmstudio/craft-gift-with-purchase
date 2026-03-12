# Release Notes for Gift with Purchase

## 5.1.0 - 2026-03-12

### Changed

- Replaced "Gift Price" (absolute price override) with "Gift Discount %" (percentage discount, 100% = free by default).
- Gift line items now show the original product price; the discount is applied as a Commerce order adjustment labeled "Offered".
- Tax calculations now use Commerce's built-in discount logic instead of manual price overrides.
- Registered custom `GiftDiscountAdjuster` via Commerce's `EVENT_REGISTER_ORDER_ADJUSTERS`.

### Removed

- Removed `giftPrice` field (replaced by `giftDiscountPercent`).
- Removed `giftValue` (declared value) field — no longer needed since original prices are preserved.
- Removed `EVENT_POPULATE_LINE_ITEM` price override handler.

## 5.0.0 - 2026-02-14

### Changed

- Updated for Craft CMS 5 and Craft Commerce 5 compatibility.
- Requires PHP 8.2+.
- Replaced `salePrice` with `promotionalPrice` for gift line item pricing (Commerce 5).
- Updated `createLineItem()` calls to use the new Commerce 5 signature.
- Replaced `getProduct()` with `getOwner()` for variant parent lookups (Commerce 5).
- Currency formatting in the control panel now uses the store's default currency instead of hardcoded EUR.
- Removed static `$plugin` singleton in favor of `getInstance()`.

## 3.0.0 - 2026-02-14

### Added

- Gift rule management with full control panel UI (create, edit, reorder, bulk enable/disable/delete).
- Condition-based triggers: cart subtotal (min/max), specific purchasables, product categories, date ranges, and user groups.
- Automatic gift addition and removal based on cart state.
- Gift price override (free, discounted, or fixed amount).
- Optional line item note on gift products.
- Auto-add and re-add on removal behavior options.
- User group targeting (Craft Pro).
- Date scheduling for time-limited promotions.
- Drag-and-drop rule priority ordering.
- Gift price persistence through Commerce recalculations via `EVENT_POPULATE_LINE_ITEM`.
- Custom events: `EVENT_BEFORE_SAVE_GIFT_RULE`, `EVENT_AFTER_SAVE_GIFT_RULE`, `EVENT_AFTER_DELETE_GIFT_RULE`.
