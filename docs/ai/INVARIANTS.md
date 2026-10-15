# ARTeuCtion — Architectural and Business Invariants

## Core domain
1. **Artwork and ArtLot are different concepts.** `Artwork` represents the cultural object; `ArtLot` is a commercial instance/sale attempt. Do not collapse them into one product entity or silently move sales history onto Artwork.
2. Preserve the current version/revision semantics for descriptions, condition reports, evidence and rights claims. A buyer-facing sale snapshot must remain interpretable after subsequent edits.
3. Claims about authenticity, provenance, ownership, condition, exhibition history, rights and SDG impact are not interchangeable. Keep claim, claimant, evidence, verification status and scope distinct where the existing model supports them.

## Money, settlement and payment
4. **EUR is the platform currency for current production flows.** Use the existing `Money` value object/integer minor units; never use floating-point arithmetic for financial calculations. Verify currency conversions are explicitly designed before introducing them.
5. Settlement splits come from a versioned/configured `SplitProfile` frozen at the relevant sale/settlement boundary. Do not hardcode percentages in controllers or UI.
6. Every settlement must reconcile gross proceeds with all applicable recipient lines, platform share, fees, tax/adjustments and reversals as defined by the approved accounting policy. Do not assume that two ledger lines account for a three-way split; inspect the persisted model.
7. Keep the three ledgers conceptually and operationally separate: transaction ledger (`ledger_entries`/settlement accounting), donation ledger (`donations` and tax evidence), and impact ledger (`impact_events` and outcome evidence).
8. Financial records and domain-event history are append-only where implemented as such. Corrections and refunds must use the established reversal/correction workflow; never silently rewrite historical accounting facts.
9. External payment events are inputs to a verified state machine, not unquestionable truth. Verify Stripe signatures, deduplicate event IDs, tolerate retries/out-of-order delivery, and make handlers safe to replay. A successful authorization/capturable state is not the same as a successful capture; a redirect is not proof of payment.
10. Do not fulfill goods or grant paid access until the canonical payment state required by the existing business flow is verified. Keep auction, Sell Now and digital-library purchase paths distinct where their lifecycle differs.
11. Database transactions and locking/idempotency guards protect concurrent financial state changes. Add regression tests for duplicate submissions, concurrent events, timeouts, partial refunds and recovery after failure.

## Auctions and real-time
12. The server/database state is authoritative for bid acceptance, current price, auction closure and settlement. HTTP responses can acknowledge the caller's committed request; Reverb distributes updates to other clients. WebSocket delivery alone is neither accounting proof nor a guaranteed delivery mechanism.
13. Preserve the distinction between a bidder's current bid and `MaxBid` ceiling, and preserve confidential reserve handling.
14. Reconnects, delayed or missed events and client clock drift must be recoverable through canonical API reconciliation. Never show an unconfirmed bid as accepted.

## API, security and privacy
15. Preserve ownership and organization boundaries in every endpoint, including nested resources, downloads and API-client scopes. UI hiding is not authorization.
16. Public caching is allowed only for intentionally public data. Authenticated/private responses, bids, offers, reserve details, evidence and paid files must not leak through shared caches or public object URLs.
17. Stable external formats (OpenAPI, IIIF, Linked Art, EDM, Schema.org, PROV-O/PREMIS where implemented) are contracts. Validate output and consider backward compatibility before changing field names, identifiers or semantics.
18. API keys are stored hashed and scoped; plaintext is returned only at creation according to the existing design. Never write keys, Stripe secrets, tokens, customer data or raw private files into logs/context packs.
19. Consent, policy acknowledgement and lawful basis are distinct concepts. Erasure requests must follow the documented retention exceptions; do not delete legally required financial records without legal and architectural review.
20. Legal/tax eligibility output is a potential eligibility/evidence aid, not a guarantee of tax relief. Do not change Bulgarian statutory logic based only on a UX requirement; require reviewed source and domain tests.

## Engineering and operations
21. Preserve the MariaDB/MySQL assumptions unless an explicitly approved migration project includes query, migration, index and integration-test changes.
22. Keep domain actions free of UI-specific behavior. Extend existing actions/contracts rather than adding duplicate workflow logic in controllers or React components.
23. Do not add packages only for novelty. Check project version constraints, maintenance, security advisories, licence and resource cost first.
24. Migrations must be safe for the supported upgrade path, reversible where reasonably possible, and tested against the project's required MariaDB test setup. Never bypass the exact test-database guard.
25. A release is not production-ready based only on passing PHP tests. Record CI at the release commit, staging payment rehearsal, webhook handling, queue/Reverb health, backup restoration, rollback, monitoring and support readiness.
