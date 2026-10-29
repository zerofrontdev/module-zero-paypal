# Zero_Paypal

PayPal for Zero Checkout, two methods. Both send the buyer to PayPal after
the order is placed; neither needs JavaScript on the shop's pages.

| Method | Code | Credentials | How it confirms |
| --- | --- | --- | --- |
| **PayPal Checkout** | `zero_paypal_checkout` | REST app: client ID and secret | Captured by the shop when the buyer comes back |
| PayPal (classic redirect) | `zero_paypal_standard` | Business account email only | PayPal's IPN (Magento's `paypal/ipn`) |

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-paypal
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-core`, `zerofront/module-zero-checkout` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## PayPal Checkout

PayPal's Orders API v2 over Magento's HTTP client, no SDK. On PayPal's page
the buyer pays with the PayPal balance, a card, or Pay Later where PayPal
offers it in the buyer's country.

1. **Placing the order** creates a PayPal order (intent CAPTURE) for the
   grand total in the order's currency; the Magento order waits in
   **pending payment** and the buyer goes to PayPal's approval page.
2. **Back on the return URL** (`zero_paypal/checkout/return`) the shop
   captures: COMPLETED for the order's amount and currency: paid (invoice,
   order email). A capture PayPal holds for review leaves the order pending
   with a comment. Declined: cancelled, the cart restored.
3. **Cancelled on PayPal** (`zero_paypal/checkout/cancel`): the order is
   cancelled, the cart restored.

No money is taken without the capture, so a buyer who never comes back pays
nothing; no webhook is needed. **Refunds:** credit memos refund the capture
online.

Settings: Stores > Configuration > Sales > Payment Methods > **PayPal
Checkout (Zero)**: Enabled, Title, **Client ID**, **Secret** (encrypted;
developer.paypal.com > Apps & Credentials), **Sandbox** (on by default).

## PayPal (classic redirect)

See `Model/Standard.php`: the `_xclick` redirect with a business email, made
paid by PayPal's IPN.

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
