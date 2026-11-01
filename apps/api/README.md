# ARTeuCtion — NestJS API (Proof of Concept)

This is the technical feasibility proof for the Laravel → NestJS migration.
It targets three specific questions before any full migration begins.

## What this proves

### 1. MariaDB connection via Prisma
Prisma connects to the existing MariaDB schema using the `mysql` connector.
The schema in `prisma/schema.prisma` maps a subset of the existing tables.
Prisma's MariaDB support handles ENUMs, timestamps, soft deletes, and BigInt columns.

### 2. Real `/books` endpoint
`GET /api/v1/books` returns the same JSON shape as Laravel's `LibraryController#index`.
The existing React frontend at `resources/frontend/` can be pointed at this API
with no frontend code changes — just change `VITE_API_BASE_URL`.

### 3. Auction concurrency — SELECT FOR UPDATE
`bid-concurrency.spec.ts` runs four tests against a real MariaDB instance:
- Sequential bids accept/reject correctly
- **Two simultaneous bids serialize correctly** — only one is accepted
- Bids on closed items are rejected
- A higher bid correctly outbids the previous leader

This directly mirrors `PlaceBid.php`'s `AuctionItem::lockForUpdate()` contract.

## Running

```bash
cd apps/api
cp .env.example .env
# Edit .env with your MariaDB credentials

npm install
npm run db:generate          # generate Prisma client from schema

# Dev server (port 3000)
npm run dev

# Concurrency test (requires MariaDB running with arteuction_test DB)
DATABASE_URL="mysql://root:@127.0.0.1:3306/arteuction_test" npm test

# OpenAPI docs
# http://localhost:3000/api/docs
```

## What this does NOT include

- Authentication (Sanctum equivalent — Phase 2 of migration)
- Write endpoints
- Stripe integration
- The full domain model

Those come after this PoC confirms the three foundation questions.
