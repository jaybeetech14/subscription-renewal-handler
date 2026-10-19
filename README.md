# WooCommerce Subscription Renewal Handler

A production WooCommerce system that automates subscription renewals end-to-end: on every
renewal payment it **grants download access** to the current month's file and **awards
tiered store credit** — with triple-redundant hook coverage, HPOS-safe renewal detection,
idempotency guards, and an admin debug log viewer.

## What it does

**On every subscription renewal:**
1. **Download access** — creates `WC_Customer_Download` permissions directly (bypassing
   `wc_downloadable_product_permissions()`, which silently fails on `processing` orders),
   then revokes stale permissions so only the current month's file is ever accessible.
2. **Store credit** — awards tier-based credit ($12 / $24 / $30 in the example config) via
   direct insert into the store-credits table, firing the balance-recalc action.

**Supporting systems:**
- **HPOS-native renewal detection** — `wcs_order_contains_renewal()` reads the legacy
  posts-meta table, which returns false on every renewal when HPOS runs with sync-on-read
  disabled. This reads `_subscription_renewal` directly from HPOS order meta instead.
- **Triple hook coverage** — `woocommerce_subscription_renewal_payment_complete`,
  `woocommerce_order_status_completed`, and `woocommerce_payment_complete`, with meta-flag
  idempotency so overlapping hooks never double-award.
- **Downloads tab fixes** — injects HPOS-invisible permissions into My Account →
  Downloads and into the order-details Downloads section, sorts newest-first, and works
  around two PHP 8.2 undefined-key bugs that broke the entire downloads list.
- **Safety net** — auto-completes renewal orders stuck in `processing`.
- **Manual grant tool** — "Grant renewal downloads & credit" order action for retries.
- **Debug log viewer** — admin page under WooCommerce showing the dedicated log file with
  download/clear actions.

## Requirements

- WordPress, WooCommerce, WooCommerce Subscriptions
- Advanced Coupons (store credit) — the credit-award helper targets its `acfw_store_credits` table
- HPOS-compatible (and specifically fixes HPOS + sync-on-read-disabled edge cases)

## Configuration

Edit the two constants at the top of the file — product IDs, credit amounts, and download
limits per tier:

```php
define( 'JBT_SUBSCRIPTION_CREDITS', array(
    114176 => 12,  // Basic   - $12 store credit / month
    114180 => 24,  // Premium - $24 store credit / month
    114181 => 30,  // VIP     - $30 store credit / month
) );
```

## Installation

Drop `woocommerce-subscription-renewal-handler.php` into a functionality plugin (it is
written as a code snippet — no `<?php` wrapper needed if your snippet manager adds one).

## Notes

- All functions are prefixed `jbt_` and constants `JBT_` to avoid collisions.
- The changelog in the file header documents real bugs found and fixed in production,
  including HPOS storage quirks and PHP 8.2 deprecations.
- Built with AI-assisted development (Claude), debugged against a live subscription store.
