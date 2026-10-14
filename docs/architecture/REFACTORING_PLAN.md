# ARTEFAK — Canonical Refactoring Plan

**Repository:** `arteuction/artefak`

**Status:** Proposed / implementation-ready after repository verification

**Scope:** Auction Infrastructure, Sale lifecycle, Payment, Settlement, Fulfillment, Cause Allocation, Party/Recipient model, Annual Author Tax Election, and supporting tests/documentation.

---

## Companion optimization roadmap

The performance/reliability priority order, R2 image strategy, runtime/DB metrics, queue controls, payment idempotency tests, and measurable acceptance criteria are maintained in the companion document: [`OPTIMIZATION_ROADMAP.md`](./OPTIMIZATION_ROADMAP.md). Read it before introducing performance tunings or starting the Settlement domain refactor.

## 0. Purpose

This document is the canonical implementation plan for bringing the Artefak codebase and its architecture documentation into alignment.

The goal is not a wholesale rewrite. The strategy is **additive where possible, explicit where required, and migration-safe**:

- preserve working concurrency and payment behavior;
- remove duplicated state-machine definitions;
- generalize Settlement without pretending the current DB schema is already generic;
- separate Settlement payouts from Cause Allocations and Donations;
- preserve current-state tables where operational code depends on them;
- add append-only event/history records beside mutable state;
- introduce Party/Recipient semantics incrementally;
- keep tax/documentation flows separate from actual donations;
- make every irreversible or legally meaningful transition auditable.

This plan intentionally distinguishes **current verified facts** from the **target architecture**.

---

# 1. Verified starting-point findings

The following facts must be treated as constraints for implementation:

### 1.1 `settlement_lines.recipient_type` is not generic today

The current migration defines:

```php
$table->enum('recipient_type', ['artist', 'fund', 'ops']);
```

Therefore the database currently rejects `author` as a legal enum value.

This is already inconsistent with the application layer because `CreateBookSettlement::insertLine()` writes `recipient_type = 'author'`, and the existing `BookPurchaseFlowTest` expects `recipient_type = 'author'`.

**Consequence:** this is a pre-existing persistence bug and a mandatory prerequisite for Settlement generalization.

### 1.2 `settlement_lines.line_type` does not exist

Any architecture text that refers to:

```text
settlement_lines.line_type = CAUSE_ALLOCATION
```

is not describing the current schema.

A new line-type dimension must be added before Settlement can distinguish payout lines from non-payout financial allocations.

### 1.3 `ProcessRefund` currently treats all settlement lines as refund participants

The current refund path retrieves settlement lines without a recipient/type restriction and delegates proportional allocation to `RefundAllocator`.

Therefore Cause Allocation extraction cannot be implemented safely without changing the refund query/policy.

### 1.4 Subscription tier is not currently present on `ArtistProfile`

`subscription_tier` is a new domain input, not an existing field that merely needs wiring.

### 1.5 Settlement tests encode the fixed three-role result shape

The existing `SettlementCalculatorTest`, `SplitProfileTest`, and `PoolSplitTest` are tied to the current three-field model and must be rewritten as part of the ruleset generalization.

### 1.6 There is currently no `Sale` model

The Sale state-machine cleanup is therefore a specification-first task, not a migration of an already-live Sale aggregate.

The existing `book_purchases.status` pattern already demonstrates the desirable separation between purchase/trade state and `settlements.status`.

### 1.7 `bid_events` can be introduced independently

`PlaceBid` / `CloseAuctionItem` already preserve current-state behavior required for locking and bid validation. Append-only event logging can be added without replacing the current `bids` table.

---

# 2. Architectural principles to freeze before implementation

These principles are normative for all following phases.

## 2.1 Domain boundaries

```text
Bid is not Sale.
Sale is not Payment.
Payment is not Settlement.
Settlement is not Donation.
Donation is not Tax Deduction.
Tax Declaration is not Donation.
```

## 2.2 Current state vs historical truth

Mutable tables remain optimized current-state projections.

Append-only event tables preserve historical facts.

Examples:

```text
bids       -> current bid state
bid_events -> bid history

auction.status -> current auction state
auction events -> historical actions
```

## 2.3 No hidden financial semantics

A `settlement_line` must never implicitly mean “send money through Stripe”. Its `line_type` determines the financial role of the line.

## 2.4 Legal intent is not legal execution

```text
Election / Intent
        !=
Eligibility Assessment
        !=
Donation Execution
        !=
Tax Reporting
```

## 2.5 Derived state must not become a second source of truth

If a status can be derived from subordinate state, it must not be independently mutated into contradictory values.

---

# 3. Target domain model

```text
AUCTION DOMAIN
    |
    +-- BID
    +-- BID_EVENT[]
    +-- BIDDER_SESSION
    +-- SELLER_DECISION
    +-- COUNTER_OFFER
    +-- AUCTION_SUSPENSION
            |
            v
          SALE
            |
     +------+---------+-----------+
     |                |           |
     v                v           v
   PAYMENT        SETTLEMENT   FULFILLMENT
     |                |           |
     +----------------+-----------+
                      |
                      v
               SALE CLOSURE PROJECTION

SETTLEMENT
    |
    +-- PAYOUT lines
    |
    +-- CAUSE_ALLOCATION lines
              |
              v
            DONATION
              |
              v
       TAX / REPORTING layer
```

---

# 4. Canonical state machines

## 4.1 Auction lifecycle

The final Auction Infrastructure implementation must support, as applicable:

- bid placement and validation;
- tie policy;
- bidder session / bid capacity;
- seller decision;
- counter offers;
- admin override;
- suspension / resume;
- close / resolution.

Every administrative or exceptional action must have an auditable reason and actor.

### Tie Policy

Tie behavior belongs to Auction/Resolution policy, not Bid.

Required persisted context for a resolution:

```text
winner_bid_id
resolution_type
tie_policy_key
tie_policy_version
resolved_at
resolution_reason
```

### Bidder Session

```text
BIDDER_SESSION
- session_id
- user_id
- auction_id
- bid_capacity_cents
- currency
- status
- started_at
- expires_at
```

`bid_capacity` is session context, not a mutable property of a historical Bid.

### Seller Decision

Do not encode counter-offers as Bid status mutations.

Use:

```text
SELLER_DECISION
- ACCEPT
- REJECT
- COUNTER_OFFER
- EXPIRE
```

and a separate:

```text
COUNTER_OFFER
- OFFERED
- ACCEPTED
- REJECTED
- EXPIRED
- CANCELLED
```

### Admin Override

An override requires:

```text
reason_code
actor_party_id / actor_user_id
previous_state
resulting_state
timestamp
immutable audit record
```

### Suspension / Resume

Suspension must preserve the reason, initiator, start time, and eventual resume reason.

---

## 4.2 Bid state/history

`bids` remains the current-state table.

Add:

```text
bid_events
- bid_id
- auction_item_id
- event_type
- amount_cents
- stripe_payment_intent_id
- timestamps
```

Recommended event types:

```text
PLACED
OUTBID
WON
CANCELED
```

`bid_events` is append-only at the application boundary. No code path should update or delete historical bid events.

---

## 4.3 Sale lifecycle

`Sale` carries only trade-level state:

```text
AGREED
  |
  +--> CANCELLED
  +--> DISPUTED
  +--> CLOSED   [derived/read-model state]
```

`CLOSED` is not a directly settable lifecycle event.

The exact closure predicate is fulfillment-mode aware:

```text
sale is closed when:
    trade is agreed
    AND payment requirement is successfully satisfied
    AND settlement is settled
    AND fulfillment requirement is satisfied
    AND no open blocking dispute exists
```

There must be no requirement that every Sale has a physical Fulfillment Order.

---

## 4.4 Payment lifecycle

Payment is independent from Settlement.

Target:

```text
PENDING
  -> PROCESSING
  -> CAPTURED

PENDING -> FAILED
PENDING -> EXPIRED

CAPTURED -> REFUNDED
CAPTURED -> CHARGEBACK
```

A captured payment is evidence about the payment rail, not evidence that money has already been distributed to settlement recipients.

---

## 4.5 Settlement lifecycle

Settlement must not absorb Payment or Fulfillment state.

Target:

```text
PENDING
  -> CALCULATED
  -> PAYOUTS_CREATED
  -> PAYOUTS_COMPLETED
  -> SETTLED
```

Operational failures may transition to a failed/retryable state where required by implementation.

A chargeback that occurs after settlement must not retroactively rewrite `SETTLED` into a historical falsehood.

Instead, post-settlement financial dispute state is represented separately:

```text
FINANCIAL_DISPUTE
- OPEN
- RESOLVED
- LOSS
- REVERSED
```

---

## 4.6 Fulfillment lifecycle

### Physical

```text
FULFILLMENT_PENDING
-> READY_TO_FULFILL
-> PACKING
-> PICKUP_SCHEDULED
-> IN_TRANSIT
-> DELIVERED
-> CUSTODY_CONFIRMED
-> FULFILLMENT_COMPLETED
```

Exceptional branch:

```text
EXCEPTION
-> RESOLVED
-> RETURNED
```

### Digital

Digital fulfillment should have a separate minimal flow, for example:

```text
PENDING
-> ACCESS_GRANTED
-> CONFIRMED
-> FULFILLMENT_COMPLETED
```

The exact status names may be aligned with the existing Digital Library implementation; the invariant is that digital completion must not depend on physical logistics.

---

# 5. Settlement schema refactor

## Phase A0 — `recipient_type` schema correction

### Objective

Make the DB contract consistent with the actual application roles.

### Migration

Expand:

```php
ENUM('artist', 'fund', 'ops')
```

to at least:

```php
ENUM('artist', 'author', 'fund', 'ops')
```

Do not introduce `operations` as a duplicate of `ops`.

### Required application work

Audit all writes and reads of `recipient_type`.

The Book Settlement path must become valid against the DB schema.

### Rollback requirement

`down()` must refuse to collapse the enum if `author` rows exist.

Conceptually:

```php
if (DB::table('settlement_lines')
    ->where('recipient_type', 'author')
    ->exists()) {
    throw new RuntimeException(
        'Cannot downgrade: author settlement lines exist.'
    );
}
```

Only after the safety check may the enum be narrowed.

### Tests

- migration up on existing data;
- insertion with `author`;
- rollback with zero `author` rows;
- rollback rejection with `author` rows;
- existing artist/fund/ops compatibility.

### Exit criterion

The DB can legally persist every role already produced by current application code.

---

# 6. Phase A1 — SettlementRuleset generalization

## Objective

Replace the hard-coded three-role calculation with a generic ruleset while preserving the existing Settlement persistence model.

### Ruleset example

```php
private const RULESETS = [
    'arteuction:v2:free' => [
        'artist' => 4500,
        'fund' => 4500,
        'ops' => 1000,
    ],

    'arteuction:v2:pro' => [
        // APPROVED BUSINESS VALUES REQUIRED
    ],

    'arteuction:v2:premium' => [
        // APPROVED BUSINESS VALUES REQUIRED
    ],

    'library:v2:standard' => [
        'author' => 8000,
        'ops' => 2000,
    ],

    'library:v2:pro' => [
        'author' => 9000,
        'ops' => 1000,
    ],
];
```

All rulesets must sum to 10,000 bps.

### Important correction

The current calculator must not hard-code:

```php
$remainderRole = 'operations';
```

The remainder role belongs to the ruleset itself.

### Proposed API

```php
SettlementRuleset::fromKey(string $key)
SettlementRuleset::forArteuction(string $planTier)
SettlementRuleset::forLibrary(string $planTier)
```

### Result shape

`SettlementResult` becomes:

```text
- gross
- lines: array<string, Money>
- rulesetKey
- rulesetVersion
- bpsByRole
```

### Subscription tier

Introduce a new domain source for plan tier.

Preferred initial placement:

```text
ArtistProfile.subscription_tier
AuthorProfile.subscription_tier
```

If a separate AuthorProfile would be artificial for the existing Library model, a user-level subscription tier may be introduced instead.

This decision must be made before wiring production settlement flows.

### Fixed code defect to avoid

`fromKey()` must check `self::RULESETS`, not a nonexistent `self::PROFILES` constant.

### Tests

- every ruleset sums to 10,000;
- unknown key throws;
- every supported tier resolves deterministically;
- rounding remainder is assigned to ruleset-defined role;
- generic result supports 2-role and 3-role settlements;
- legacy ArteUction free split remains numerically identical;
- Library standard/pro outputs match current business rules;
- currency propagation remains correct.

### Exit criterion

No production settlement path depends on a fixed `artist/fund/ops` result shape.

---

# 7. Phase B0 — Introduce `settlement_lines.line_type`

## Objective

Separate “who is this line for?” from “what kind of financial line is this?”.

### Design

Keep:

```text
recipient_type = artist | author | fund | ops | ...
```

Add:

```text
line_type = payout | cause_allocation
```

### Recommendation

Use a compact persisted string plus application-level enum/domain validation rather than introducing another brittle DB enum unless the repository has a strong policy requiring DB enums for this dimension.

The goal is to avoid repeating the exact long-term extensibility problem that was just found in `recipient_type`.

### Migration

All existing rows default to:

```text
line_type = payout
```

because all current settlement lines participate in the existing payout/refund interpretation until explicitly extracted.

### Tests

- existing rows default to payout;
- new cause-allocation row can be persisted;
- invalid line type is rejected at domain boundary;
- serialization/deserialization remains stable.

---

# 8. Phase B1 — Extract Cause Allocation from payout semantics

## Objective

A fund allocation should remain visible in the settlement ledger but should not automatically become a Stripe payout.

### `cause_allocations`

Target fields:

```text
id
settlement_id
settlement_line_id nullable
cause / recipient reference
amount_cents
currency
status
created_at
updated_at
```

Recommended lifecycle:

```text
ALLOCATED
  -> DONATION_PENDING
  -> DONATION_RECORDED

ALLOCATED -> CANCELLED
DONATION_PENDING -> CANCELLED
```

### Settlement representation

A Cause Allocation line should be:

```text
recipient_type = fund
line_type = cause_allocation
```

It remains part of the financial ledger but is excluded from generic payout transport.

### SettleAuction behavior

Instead of creating a fund payout transfer directly:

1. create the settlement line;
2. create the cause allocation;
3. do not enqueue a Stripe transfer for that line;
4. hand off donation execution to the Legal & Impact flow when available.

---

# 9. Phase B2 — Refund policy for Cause Allocations

This is a mandatory design decision, not an implementation detail.

## Rule 1 — Cause allocation not yet donated

If the cause allocation is only:

```text
ALLOCATED
DONATION_PENDING
```

and the underlying Sale is refunded, the cause allocation is eligible for reversal/cancellation.

The refund workflow must explicitly update the cause allocation; it must not accidentally inherit whatever behavior `RefundAllocator` had before Phase B.

## Rule 2 — Donation already recorded

If:

```text
DONATION_RECORDED
```

the line is **not** silently passed to the ordinary settlement refund allocator.

The normal refund allocator operates on eligible payout lines only.

A post-donation recovery/reversal becomes a separate financial/legal case if the applicable donation/payment/legal workflow permits it.

There must be no hidden “reverse the donation proportionally” behavior.

## Default implementation policy

Until a separate Donation Recovery policy is implemented:

```text
RefundAllocator
    -> payout lines only

Cause Allocation
    -> explicit cause-allocation reversal/cancellation logic

Recorded Donation
    -> excluded from ordinary refund allocation
```

The business/legal disposition of the customer refund cost after an already-recorded donation must be explicitly configured/documented before enabling that path in production.

---

# 10. Phase B3 — Audit every payout/transfer query

After `line_type` exists, search the entire codebase for queries and loops over `settlement_lines`.

Every path that truly means “payout/transfer” must explicitly scope to:

```text
line_type = payout
```

At minimum review:

- Stripe transfer creation;
- payout preparation;
- reconciliation;
- payout reporting;
- refund allocation;
- transfer outbox generation;
- payout retry/failure handlers;
- settlement completion logic.

This prevents future code from accidentally treating every settlement line as a payout again.

---

# 11. Phase C — Append-only Bid Events

## Objective

Add an immutable historical log without replacing the current mutable `bids` state.

### Schema

```text
bid_events
- id
- bid_id
- auction_item_id
- event_type
- amount_cents
- stripe_payment_intent_id nullable
- timestamps
```

### Write-through points

Any existing code that changes:

```php
$bid->status = ...;
$bid->save();
```

must emit the corresponding event in the same transaction where the state transition is semantically meaningful.

### Concurrency invariant

Do not move winner calculation or `nextBidCents()` logic into the event table. The mutable `bids` table remains the locking/current-state source required by the existing concurrency implementation.

### Tests

- one event per intended transition;
- events are written transactionally;
- failed transaction produces neither state nor event;
- historical events are immutable;
- current bid state remains unchanged in behavior.

---

# 12. Phase D — Party / PartyRole recipient foundation

## Objective

Introduce real legal/financial parties without migrating the entire User model.

### Tables

```text
parties
- id
- party_type
- legal_name
- user_id nullable
- timestamps

party_roles
- id
- party_id
- role
- timestamps
```

Roles may include:

```text
ARTIST
AUTHOR
FUND
RECIPIENT
DONOR
```

### Migration strategy

Do not replace `users.role` globally.

Use Party as the new financial-recipient/legal identity layer for newly created financial records.

Existing records remain readable through compatibility fields until a later migration is justified.

### Settlement integration

`settlement_lines` should eventually be able to reference the Party behind a recipient, while keeping `recipient_type` as the compact role classification.

---

# 13. Phase E — Naming cleanup

Replace obsolete financial/legal display naming:

```text
ArtMetro Social Fund
```

with the approved current name:

```text
ArteUction Social Fund
```

Do **not** rename the existing code namespace:

```text
App\Domain\Artmetro
```

unless a separate migration proves that namespace cleanup has material value.

The code namespace and business entity name are different concerns.

Add a code comment/documentation note where necessary to prevent future developers from conflating the two.

---

# 14. Phase F — Annual Author Tax Election

## Objective

Add a yearly author-initiated donation election flow for Digital Library authors without modifying the per-sale Cause Allocation model.

### Separation

```text
Cause Allocation
= per-sale / transaction-context allocation

Annual Author Election
= once-yearly author declaration from author income/tax base
```

These must remain separate entities and workflows.

### Annual Author Statement

Recommended structure:

```text
annual_author_statements
- statement_id
- author_party_id
- jurisdiction_id
- tax_year
- platform_gross_revenue_cents
- platform_settlement_income_cents
- platform_fees_cents
- tax_base_cents nullable
- tax_base_source
- tax_regime
- currency
- status
- finalized_at
- finalized_by
```

### Important rule

Do not assume the platform can universally derive an author's legal/taxable profit from settlement lines.

Possible provenance:

```text
PLATFORM_DERIVED
AUTHOR_DECLARED
ACCOUNTING_IMPORTED
ACCOUNTANT_CONFIRMED
```

### Annual Donation Election

```text
annual_donation_elections
- election_id
- statement_id
- option_id / recipient_id
- declared_rate
- requested_amount_cents
- eligible_amount_cents
- disallowed_amount_cents
- category_cap_cents
- aggregate_cap_cents
- remaining_aggregate_capacity_cents
- eligibility_assessment_id
- donation_id nullable
- status
- finalized_at
```

### Legal evaluation order

```text
requested amount
      ↓
recipient eligibility
      ↓
category-specific limit
      ↓
annual aggregate limit
      ↓
document/evidence requirements
      ↓
eligible amount
      ↓
author confirmation
      ↓
actual donation
      ↓
tax reporting
```

The Legal Ruleset/Eligibility engine remains the source of legal constraints.

### 65% aggregate handling

The engine must evaluate the author's annual aggregate selection as a whole, not independently per election.

If requested selections exceed the applicable aggregate capacity, the system must produce an explicit result:

```text
requested
eligible
not_eligible
reason
```

The system must not silently clamp an election and present the result as if the full requested donation occurred.

### Donation boundary

```text
Annual Election
    ↓
Donation Eligibility Assessment
    ↓
Author Confirmation
    ↓
Donation
    ↓
Evidence / Receipt
```

An election is not itself a Donation.

---

# 15. Tax Declaration / НАП integration boundary

The first implementation should support document generation/validation without assuming that the platform is the authoritative tax-accounting system.

Target lifecycle:

```text
DRAFT
-> VALIDATED
-> GENERATED
-> SUBMISSION_REQUESTED
-> SUBMITTED
-> ACCEPTED

SUBMITTED -> REJECTED
```

A `SUBMITTED` status must only mean that a real submission request has been accepted by the relevant transport/service and that a reference/result is stored.

Do not treat a locally generated XML/PDF as equivalent to successful submission.

Store, where available:

```text
submission_reference
transport_request_id
submitted_at
accepted_at
response_hash
error_code
```

Any production integration must be implemented against the current НАП service/schema and reviewed by the responsible accountant/legal counsel before automated filing is enabled.

---

# 16. Implementation order

The recommended order is:

```text
PHASE 0
Canonical architecture + state machines + Auction Infrastructure merge

A0
recipient_type DB correction

A1
SettlementRuleset / generic SettlementResult

B0
settlement_lines.line_type

B1
CauseAllocation extraction

B2
Refund policy segregation

B3
Payout/transfer query audit

C
BidEvent append-only log

D
Party / PartyRole

E
Naming cleanup

F
Annual Author Tax Election

G
Tax Declaration / submission adapter
```

`C` and `E` are operationally independent and may be developed in parallel once the canonical specification is frozen.

---

# 17. Dependency graph

```text
Phase 0
   |
   v
  A0
   |
   v
  A1
   |
   +------> B0 --> B1 --> B2 --> B3
   |
   +------> C
   |
   +------> D
   |
   +------> E
   |
   v
  F --> G
```

The only hard dependency for `F` is the availability of the generic settlement/income evidence and Legal & Impact recipient/eligibility primitives. It does not depend on ArteUction-specific fund percentages.

---

# 18. Migration discipline

Every schema migration must follow these rules:

1. **Backward readability first** where practical.
2. **No destructive enum narrowing without data preflight.**
3. **Backfill defaults explicitly.**
4. **Do not use a down migration that silently remaps one business role to another.**
5. **Record migration assumptions in the migration file itself.**
6. **Add integration tests for both pre-existing and newly introduced values.**

For enum changes, the migration must answer:

```text
Can old rows be read?
Can new rows be written?
Can this migration be reversed without semantic data loss?
```

If the answer to the last question is “no”, the rollback must fail loudly rather than corrupt meaning.

---

# 19. Code audit checklist

Before declaring the refactor complete, search the codebase for:

```text
recipient_type
settlement_lines
RefundAllocator
transfer_outbox
SettlementCalculator
SplitProfile
SettlementResult
CreateBookSettlement
SettleAuction
PlaceBid
CloseAuctionItem
book_purchases.status
```

For each occurrence classify it as one of:

```text
read-current-state
write-current-state
historical-event
payout-only
cause-allocation-only
refund-only
legal/tax
```

No ambiguous usage should remain in financial code.

---

# 20. Required test matrix

## Settlement

- 2-role Library settlement;
- 3-role ArteUction settlement;
- rounding;
- currencies;
- invalid ruleset;
- invalid role;
- tier resolution;
- legacy free split regression.

## Schema

- `author` recipient persistence;
- enum rollback safety;
- existing `artist/fund/ops` records;
- `line_type` backfill;
- cause-allocation persistence.

## Refunds

- refund before donation;
- refund while donation pending;
- refund after donation recorded;
- mixed payout/cause settlement;
- partial refund;
- repeated refund/retry idempotency.

## Auction

- concurrent bids;
- tie resolution;
- session capacity;
- seller counter-offer;
- admin override audit;
- suspension/resume;
- append-only bid events.

## Sale

- Sale is not independently set to PAID;
- payment/settlement disagreement is representable;
- physical fulfillment closure;
- digital fulfillment closure;
- cancellation;
- dispute blocking closure.

## Tax / Annual Election

- multiple elections aggregate together;
- category cap;
- aggregate cap;
- insufficient remaining annual capacity;
- rejected recipient;
- evidence missing;
- election does not create Donation before confirmation;
- tax declaration references actual eligibility evidence.

---

# 21. Idempotency and transaction rules

All money-moving transitions must be idempotent.

At minimum:

```text
settlement calculation
payout creation
payout retry
refund creation
cause-allocation creation
Donation creation
Tax submission request
```

Where state and event are paired, they must be written in the same DB transaction where the business invariant requires atomicity.

Examples:

```text
Bid state change + BidEvent
Settlement line + CauseAllocation
Settlement payout record + transfer outbox record
Election confirmation + Donation intent record
```

External transport confirmation must never be inferred only from the local transaction commit.

---

# 22. Observability / audit requirements

Every financially significant action should be traceable through stable IDs:

```text
auction_id
auction_item_id
bid_id
sale_id
payment_id
settlement_id
settlement_line_id
cause_allocation_id
donation_id
party_id
financial_dispute_id
```

Logs should never rely only on display names such as “ArteUction Social Fund”.

---

# 23. Decision Register

The following items require explicit product/legal/business approval before their implementation is frozen:

| ID | Decision | Owner | Blocking phase |
|---|---|---|---|
| D-01 | ArteUction `free/pro/premium` bps values | Product/Business | A1 |
| D-02 | Exact subscription-tier storage location | Product/Engineering | A1 |
| D-03 | Whether `line_type` uses string/domain enum or DB enum | Engineering | B0 |
| D-04 | Pre-donation cause allocation cancellation rules | Finance/Product | B2 |
| D-05 | Who economically bears refunds after an already-recorded donation | Finance/Legal/Product | B2 |
| D-06 | Exact Sale closure predicate per fulfillment mode | Engineering/Product | Phase 0 |
| D-07 | Counter-offer expiration duration/policy | Product | Phase 0 |
| D-08 | Admin override reason-code taxonomy | Engineering/Operations | Phase 0 |
| D-09 | Annual Author tax-base provenance policy | Tax/Accounting | F |
| D-10 | Exact НАП submission service/schema and authorization model | Tax/Engineering | G |

No implementation should invent D-01 or D-05 implicitly in application code.

---

# 24. Definition of Done

The refactor is complete only when all of the following are true:

- DB schema accepts every role the application intentionally writes;
- `recipient_type` and `line_type` have distinct meanings;
- payout queries explicitly exclude non-payout lines;
- refunds have an explicit Cause Allocation policy;
- Settlement no longer encodes Payment or Fulfillment state;
- Sale has no duplicated payment/settlement/fulfillment statuses;
- closure is derived and fulfillment-mode aware;
- Bid history is append-only while current bid state remains performant/lockable;
- seller counter-offers and admin overrides are auditable objects/events;
- all Settlement calculations are ruleset-driven;
- no code path assumes exactly three settlement roles;
- Party/Recipient identity is available for new legal/financial records;
- Annual Author Election is separate from per-sale Cause Allocation;
- Donation intent, eligibility, execution, and tax reporting are distinct;
- migration rollbacks fail safely instead of silently remapping semantics;
- the full regression/integration test matrix passes.

---

# 25. Suggested Git documentation structure

Once repository access is available, place the canonical documents approximately as follows:

```text
docs/
  architecture/
    REFACTORING_PLAN.md              # this document
    STATE_MACHINES.md                 # canonical lifecycle diagrams/rules
    SETTLEMENT_MODEL.md               # SettlementRuleset + line semantics
    AUCTION_INFRASTRUCTURE.md         # Bid/Event/Session/Seller/Admin model
    LEGAL_IMPACT_BOUNDARIES.md        # Donation vs Cause vs Tax
  migrations/
    SETTLEMENT_MIGRATIONS.md          # A0/B0 migration notes + rollback rules
  testing/
    REFACTOR_REGRESSION_MATRIX.md     # phase-by-phase test matrix
```

The root `README` or architecture index should link to `docs/architecture/REFACTORING_PLAN.md` as the canonical refactoring entry point.

---

# 26. Commit / PR strategy

Prefer small, behavior-preserving PRs over one large refactor.

Suggested sequence:

```text
PR 01 — docs: freeze canonical state machines
PR 02 — migration: allow author settlement recipient type
PR 03 — refactor: generic SettlementRuleset
PR 04 — migration: add settlement line type
PR 05 — refactor: extract CauseAllocation
PR 06 — refactor: refund/payout segregation
PR 07 — feat: append-only bid events
PR 08 — feat: Party / PartyRole recipient layer
PR 09 — chore: ArteUction naming cleanup
PR 10 — feat: Annual Author Election
PR 11 — feat: Tax Declaration adapter
```

Each PR should include:

```text
scope
migration impact
backward compatibility
invariants
tests
rollback notes
```

---

# 27. Final architecture statement

The target architecture is not a rewrite of Artefak. It is a controlled separation of concerns around the financial and auction lifecycle:

```text
AUCTION
  -> BID / EVENTS / SESSION / SELLER DECISION
  -> SALE
  -> PAYMENT
  -> SETTLEMENT
       -> PAYOUT
       -> CAUSE ALLOCATION
  -> FULFILLMENT

CAUSE ALLOCATION
  -> DONATION ELIGIBILITY
  -> DONATION

ANNUAL AUTHOR ELECTION
  -> LEGAL/TAX ELIGIBILITY
  -> DONATION
  -> TAX REPORTING
```

The central implementation rule is:

> **Do not make the code generic by assumption. Make the persistence contract, domain contract, financial boundaries, and audit trail generic in that order.**

