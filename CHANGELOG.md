# Changelog: zerofront/module-zero-paypal

## 1.3.6

- Returns and cancellations settle under the order's lock: an order paid meanwhile (the IPN came first) is never cancelled by the cancel page.
- PayPal Standard's cancel page cancels only the customer's own PayPal Standard order still waiting for payment, under the order's lock, and gives the cart back as it was; an order paid meanwhile (the IPN came first) is left alone.
- Refunds and captures carry a `PayPal-Request-Id`, so a retry never refunds or captures twice; refunds are in the order's currency.
- Array-valued parameters in PayPal's calls no longer cause a 500.

Earlier releases (1.3.5 and before): see the Git tags.
