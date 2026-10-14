# Operational Runbooks (Phase 157)

## 1. Stuck payment

**Symptom:** Buyer paid via Stripe; `auction_items.status` or `sell_now_offers.status`
remains `payment_pending` after 10 minutes.

**Steps:**
1. Find the payment intent in Stripe Dashboard → Payments.
2. Check `settlements` table: `SELECT * FROM settlements WHERE stripe_payment_intent_id = 'pi_...'`
3. If settlement exists but status = `pending`: check `failed_jobs` for `DispatchStripeTransfer`.
4. If settlement missing: check `stripe_events` / Stripe webhook logs for `payment_intent.succeeded`.
   - If event was delivered but not processed: `php artisan queue:retry all`
   - If event was never delivered: resend from Stripe Dashboard.
5. If payment intent status = `requires_action` in Stripe: contact buyer to complete 3DS.
6. If payment intent is `canceled` in Stripe: mark offer/lot as `payment_failed` manually:
   ```bash
   php artisan tinker --execute="
     \$offer = \App\Models\SellNowOffer::find(<id>);
     \$offer->update(['status' => 'payment_failed']);"
   ```
   Then notify buyer via `php artisan notify:payment-failed <offer_id>` (if command exists)
   or send manual email.

---

## 2. Unmatched ledger entry

**Symptom:** Reconciliation run reports `mismatch_count > 0`.

**Steps:**
1. `php artisan ops:reconcile --run-id=<id>` to view mismatch detail.
2. Check `reconciliation_mismatches` table for the affected settlement.
3. Common causes:
   - **Transfer arrived late:** Stripe transfer created but not yet reflected. Wait 24h and re-run.
   - **Partial payout:** One settlement_line disbursed, sibling failed. Check `transfer_outbox`.
   - **Rounding error:** Artist/fund/ops splits don't sum to gross_cents. Check the split profile.
4. To correct: create a new `ledger_entries` row with `type = 'adjustment'` and a note explaining
   the correction. Never edit or delete existing ledger rows.
5. Mark mismatch resolved via admin API: `PATCH /api/v1/admin/reconciliation/mismatches/<id>`
   with `{ "resolution": "...", "resolved_by": <admin_user_id> }`.

---

## 3. Failed Stripe transfer

**Symptom:** `transfer_outbox` row has `status = failed` or `failed_jobs` contains
`DispatchStripeTransfer`.

**Steps:**
1. Check the Stripe Dashboard → Payouts for the connected account.
2. Common reasons: connected account not fully verified, bank details invalid, balance insufficient.
3. Fix the connected account issue in Stripe, then:
   ```bash
   php artisan queue:retry <job-id>
   # or retry via admin API:
   POST /api/v1/ops/transfer-outbox/retry
   ```
4. If the transfer cannot succeed (account closed, wrong currency):
   - Create a manual ledger entry with `type = 'hold'` to mark the amount as pending resolution.
   - Open a dispute record via `POST /api/v1/disputes` with `type = payout_failure`.

---

## 4. Disputed transaction

**Symptom:** Stripe sends `charge.dispute.created` webhook, or buyer contacts support.

**Steps:**
1. Locate the dispute: `GET /api/v1/disputes?stripe_dispute_id=<id>`
2. Assign to a resolver: `POST /api/v1/disputes/<id>/assign` with `{ "resolver_id": <user_id> }`
3. Gather evidence: `POST /api/v1/disputes/<id>/evidence` with photos, tracking, comms.
4. Respond via Stripe Dashboard within the deadline (typically 7 days).
5. If dispute is lost: Stripe reverses the charge. Record via a new `ledger_entries` row
   with `type = 'debit'` referencing the original settlement.
6. Settle any artist/gallery overpayment via a contra-settlement or manual adjustment.

---

## 5. Compromised credentials

**Symptom:** Suspected leaked API key, Stripe secret, or admin password.

**Immediate steps (within 5 minutes):**
1. Rotate the compromised credential immediately:
   - Stripe key: Stripe Dashboard → Developers → API keys → Roll key.
   - App key: `php artisan key:generate` then redeploy.
   - Database password: change in MariaDB + update `.env`.
2. Update the secret in production environment and restart all workers/services.
3. Review Stripe event log for unauthorized charges (last 24h).
4. Review `activity_log` table: `SELECT * FROM activity_log WHERE created_at > NOW() - INTERVAL 1 HOUR`
5. If unauthorized financial activity is found:
   - Contact Stripe support immediately.
   - Do not process new payments until scope is confirmed.
6. File an incident report in `docs/incidents/`.

---

## 6. GDPR data request (Art. 17 / Art. 20)

**Symptom:** User submits a data access or erasure request.

**Data export (Art. 20 — portability):**
```bash
php artisan gdpr:export --user=<user_id> --output=/tmp/export_<user_id>.zip
```
Deliver the ZIP to the user within 30 days.

**Erasure (Art. 17 — right to be forgotten):**
Financial records cannot be deleted under Bulgarian accounting law (7-year retention).
Erasure applies to: profile data, contact details, artwork images, bids (pseudonymised).

1. Pseudonymise the user record (replace email/name with anonymised token).
2. Soft-delete artwork images from S3.
3. Retain: ledger_entries, settlements, donations, impact_events (legally required).
4. Log the erasure action in `activity_log`.
5. Respond to the user within 30 days confirming what was erased and what was retained and why.

---

## 7. Pilot transaction limits

During the limited pilot (Phase 158), enforce these hard limits via the `auction_ruleset`:

| Limit | Value |
|-------|-------|
| Max bid per user per auction | EUR 5,000 |
| Max sell-now offer | EUR 10,000 |
| Max single book purchase | EUR 500 |
| Simultaneous open auctions | 3 |

Monitor daily via: `GET /api/v1/ops/summary`

Escalation contact for any transaction > EUR 1,000: [define before pilot launch].
