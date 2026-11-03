# ARTeuCtion — Stripe Test Mode Results

**Date:** 2026-11-03  
**Status:** PENDING — Stripe test-mode keys not yet configured in staging

---

## Prerequisites

```bash
# Required environment variables for staging Stripe test mode
STRIPE_SECRET_KEY=sk_test_...        # Stripe test secret key
STRIPE_WEBHOOK_SECRET=whsec_...      # Webhook signing secret (from Stripe CLI or dashboard)
```

---

## Crash Recovery Verification Matrix

All three crash scenarios verified in unit tests (SC13–SC19). The following require live Stripe test mode.

| Scenario | Method | Expected | Result |
|---|---|---|---|
| A: Normal dispatch | `createTransfer` with idempotency_key | Transfer created, `stripe_transfer_id` saved | — |
| A replay: same idempotency_key within 24h | `createTransfer` again | Stripe returns original transfer (no duplicate) | — |
| B: Crash after Stripe, before DB write | Simulate by killing worker after API call | `findTransferByGroup` finds existing on retry | — |
| B: Verify `external_ref` in metadata | `stripe.transfers.retrieve(id)` | `metadata.external_ref` matches outbox row | — |
| C: Crash after `stripe_transfer_id` saved | Simulate by setting status='processing' manually | `retrieveTransfer` finds, marks completed | — |
| C: 24h+ delayed retry | Wait for Stripe idempotency window (or test manually) | `retrieveTransfer` still finds transfer | — |
| Ambiguous: multiple transfers in group | Not expected with UUID external_ref | Logs reconciliation incident, does not create | — |

---

## Transfer Verification Protocol

Each created transfer must be verified:

```bash
# Stripe CLI: list transfers for a transfer_group
stripe transfers list --transfer-group="outbox:to-{id}-{ts}"

# Expected response:
# {
#   "data": [{
#     "id": "tr_...",
#     "amount": <amount_cents>,
#     "currency": "eur",
#     "destination": "acct_...",
#     "transfer_group": "outbox:to-{id}-{ts}",
#     "metadata": {
#       "outbox_id": "{id}",
#       "external_ref": "to-{id}-{ts}"
#     }
#   }]
# }
```

Verification checks (per user requirement):
- [ ] `metadata.external_ref` matches outbox `external_ref` column
- [ ] `destination` matches `settlement_lines.stripe_account_id`
- [ ] `currency` matches `transfer_outbox.currency` (lowercase)
- [ ] `amount` matches `transfer_outbox.amount_cents`
- [ ] No duplicate transfer in same `transfer_group`

---

## End-to-End Checkout Flow

| Step | Expected | Result |
|---|---|---|
| `POST /stripe/checkout` → `checkout.sessions.create` | Returns `checkout_url` | — |
| User completes Stripe test checkout | Webhook `payment_intent.succeeded` fired | — |
| Webhook handler creates settlement | `settlements.status = 'received'` | — |
| Replay same webhook | Settlement unchanged (idempotent) | — |
| Scheduler dispatches transfer_outbox | `transfer_outbox.status = 'completed'` | — |
| `stripe_transfer_id` persisted | Non-null in DB | — |
| `external_ref` in Stripe metadata | Matches DB | — |

---

## Test Cards (Stripe test mode)

| Card | Scenario |
|---|---|
| `4242 4242 4242 4242` | Successful payment |
| `4000 0000 0000 0002` | Always declined |
| `4000 0025 0000 3155` | Requires 3DS authentication |

Webhook test: `stripe trigger payment_intent.succeeded`

---

## Results (To Be Filled After Staging Run)

All items above: — (pending staging deployment with Stripe test keys)
