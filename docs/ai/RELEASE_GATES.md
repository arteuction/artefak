# ARTeuCtion — Release Gates

A release may pass one gate while failing another. Do not mark a gate `PASS` without evidence tied to the candidate commit and environment.

| Gate | Evidence required | Block release when |
|---|---|---|
| Source control | Reviewed PR, clean candidate SHA, required branch protection | Candidate includes unreviewed or unknown commits |
| Backend | Required PHP 8.4/8.5 CI and MariaDB 11 migration/test jobs passed | Any required suite skipped, failed or ran on an unverified candidate |
| Frontend | Type check, production build, Playwright critical journeys | Build/type errors or broken critical journeys |
| Security | Composer audit, OSV, secret scan, reviewed suppressions, auth/ownership regression tests | Unresolved critical/high finding or unexplained suppression |
| Payment | Stripe test-mode evidence for auction, Sell Now, library, retry/duplicate event, failure/refund/reversal/payout paths | Duplicate settlement, premature fulfillment, unrecoverable payment/payout state |
| Accounting | Settlement conservation and reconciliation report | Any unexplained ledger mismatch |
| Async/realtime | Queue/outbox retries, worker restart, Reverb reconnect/API reconciliation | Lost or duplicated business transition without recovery |
| Media and privacy | Authorization checks for evidence/book files, safe cache headers and object access | Private data can be fetched via shared cache or unprotected URL |
| Operations | Verified backup restore, rollback rehearsal, alerts and runbooks | Only an untested backup or undocumented manual recovery exists |
| Performance | Measured resource/latency results on production-like instance | OOM/restarts, material data loss or unacceptable critical-path latency |
| Public preview | TLS, policy/contact pages, mobile/navigation smoke tests, no private data exposure | Broken basic experience or privacy/security blocker |
| Public transactions | Pilot evidence, support coverage, refund/dispute process, legal/payment review as applicable | Critical operational or financial risk remains open |

`public preview` and `public transactional launch` are different approvals. Do not gate a safe non-transactional preview on unrelated features such as the future native Android app, but do not enable payments until all relevant payment and operational gates pass.
