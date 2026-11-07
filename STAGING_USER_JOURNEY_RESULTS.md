# ARTeuCtion — Staging User Journey Results

**Date:** 2026-11-03  
**Status:** PENDING — staging deployment not yet executed

---

## Environment

- React/Vite frontend: `https://staging.arteuction.com` (or localhost:5173)
- NestJS API: `https://api.staging.arteuction.com` (or localhost:3000)
- Laravel: remains on production, NOT used for staging validation
- Database: sanitized copy of production, staging MariaDB
- Stripe: test mode only (`sk_test_...`)

---

## Journey Results

| # | Journey | Actor | Steps | Status | Notes |
|---|---|---|---|---|---|
| J1 | Register + email verify | Buyer | POST /auth/register, verify token | — | — |
| J2 | Login + JWT issue | Buyer | POST /auth/login, check token expiry | — | — |
| J3 | Artist creates artwork + S3 presigned upload | Artist | POST /artworks/presign, PUT to S3 | — | — |
| J4 | Admin creates auction + publishes lot | Admin | POST /auctions, POST /auction-items | — | — |
| J5 | Public user watches auction without auth (WS) | Anonymous | WS connect, subscribe-lot, no auth | — | — |
| J6 | Buyer places bid + WS broadcast fires | Buyer | POST /bids, observe bid:placed on WS | — | — |
| J7 | Second buyer outbids, WS update confirmed | Buyer 2 | POST /bids higher, observe bid:placed | — | — |
| J8 | Auction closes, winner notified via WS private room | Winner | WS lot:won on user:{id} room | — | — |
| J9 | Winner completes Stripe test checkout | Winner | GET checkout URL, use test card | — | — |
| J10 | Stripe webhook → settlement created (idempotent replay) | System | Replay webhook, verify no duplicate | — | — |
| J11 | Transfer dispatched to artist's Stripe account | Artist | transfer_outbox.status=completed | — | — |
| J12 | Book purchase + presigned download | Buyer | POST /books/{id}/purchase, GET download | — | — |
| J13 | Donation submitted + impact evidence linked | Donor | POST /donations, POST /evidence | — | — |
| J14 | Ledger integrity: three tables never mixed | Admin | SQL audit queries | — | — |

---

## Per-Journey Acceptance Detail

### J3 — Artwork Upload
- [ ] Presigned PUT URL returned with correct content-type restriction
- [ ] File uploaded to S3 quarantine prefix (not `artworks/` directly)
- [ ] Artwork `primary_image_status` remains `pending` until verified
- [ ] Original not publicly accessible until approved

### J5 — Public WebSocket
- [ ] Connected without Authorization header
- [ ] `subscribe-lot` returns `subscribed` ack
- [ ] `subscribe-user` returns `UNAUTHORIZED` error
- [ ] `bid:placed` received with no `bidder_id` field

### J8 — Winner Notification
- [ ] Lot closes → `lot:closed` broadcast to all (no `winner_user_id`)
- [ ] Winner's private `user:{id}` room receives `lot:won` with full detail
- [ ] Non-winner does not receive `lot:won`

### J10 — Webhook Idempotency
- [ ] Second identical webhook: settlement unchanged
- [ ] `ledger_entries` count unchanged after replay
- [ ] transfer_outbox row not duplicated

### J11 — Transfer Dispatch
- [ ] `transfer_outbox.external_ref` non-null
- [ ] Stripe transfer `metadata.external_ref` matches
- [ ] `transfer_outbox.stripe_transfer_id` non-null after completion
- [ ] No duplicate transfer in Stripe dashboard

### J14 — Ledger Integrity Queries
```sql
-- Must return 0 rows
SELECT l.* FROM ledger_entries l
JOIN donations d ON d.id = l.related_id AND l.related_type = 'donation';

SELECT l.* FROM ledger_entries l  
JOIN impact_events ie ON ie.id = l.related_id AND l.related_type = 'impact_event';

-- ledger_entries may only contain these types
SELECT DISTINCT type FROM ledger_entries;
-- Expected: ['sale_split', 'refund_debit']
```

---

## Rollback Verification (J15)

After completing journeys J1–J14:

1. Stop NestJS
2. Confirm Laravel still serves production
3. Confirm no data in Laravel production DB was modified by staging tests
4. Confirm staging MariaDB changes are isolated
5. Restore from pre-test snapshot (if applicable)

| Step | Result |
|---|---|
| NestJS stopped | — |
| Laravel still serving | — |
| No production contamination | — |
| Staging snapshot restored | — |
