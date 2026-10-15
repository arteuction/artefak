---
paths:
  - "app/Domain/**/Settlement*.php"
  - "app/Domain/**/Money*.php"
  - "app/Domain/**/PlaceBid.php"
  - "app/Domain/**/SettleAuction.php"
  - "app/Domain/**/CreateSettlement.php"
  - "app/Domain/**/ProcessRefund.php"
  - "app/Jobs/**Stripe*.php"
  - "app/Jobs/**Transfer*.php"
  - "app/Http/Controllers/Api/V1/**Bid*.php"
  - "app/Http/Controllers/Api/V1/**Checkout*.php"
  - "app/Http/Controllers/Api/V1/**Settlement*.php"
  - "app/Http/Controllers/Api/V1/**Refund*.php"
  - "tests/**/Payment*.php"
  - "tests/**/Settlement*.php"
---
# Backend financial-domain rules

- Read `docs/ai/INVARIANTS.md` before editing.
- Inspect the exact domain action, its callers, migration/schema, events, webhook path and tests. Do not duplicate business rules in a controller.
- Use the existing Money type and integer minor units; never use float for money.
- Preserve split-profile/version semantics and all applicable settlement lines. Add conservation assertions.
- Preserve DB transactions, locking, idempotency, append-only facts and reversal workflows. Test duplicate, concurrent, out-of-order, partial-failure and replay cases where relevant.
- Distinguish authorization/capturable from captured/succeeded; distinguish payment confirmation from fulfillment, payout and reconciliation.
- Never test against a non-test database or expose Stripe secrets. State which integration behavior is mocked vs verified against Stripe test mode.
- Before finishing, report accounting invariants checked, tests actually run and unverified external-provider behavior.
