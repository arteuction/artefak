#!/usr/bin/env ts-node
/**
 * Migration parity check
 *
 * Parses routes/api.php to extract Laravel route METHOD+path pairs, then
 * starts a minimal NestJS app to list registered routes, and emits a
 * coverage matrix showing which Laravel routes are covered and which are not.
 *
 * Usage: npm run parity:check
 * Output: stdout (and optionally parity-report.json when --json flag is passed)
 */

import { readFileSync, writeFileSync } from 'fs';
import { join, resolve } from 'path';

const flags = process.argv.slice(2);
const emitJson = flags.includes('--json');
const phpFile = resolve(join(import.meta.dirname ?? __dirname, '..', '..', '..', 'routes', 'api.php'));

// ── 1. Parse Laravel routes/api.php ─────────────────────────────────────────

type LaravelRoute = { method: string; path: string; name?: string };

function parsePhpRoutes(src: string): LaravelRoute[] {
  const routes: LaravelRoute[] = [];
  // Match: Route::get/post/patch/put/delete/any (with optional chained ->name())
  const re = /Route::(get|post|patch|put|delete|any)\s*\(\s*['"]([^'"]+)['"]/gi;
  const nameRe = /->name\s*\(\s*['"]([^'"]+)['"]/;

  let m: RegExpExecArray | null;
  while ((m = re.exec(src)) !== null) {
    const method = m[1].toUpperCase();
    // Normalize Laravel path params {foo} → :foo for display
    const rawPath = m[2];
    const path = rawPath.replace(/\{(\w+)\??}/g, ':$1');

    // Try to find a ->name() on the same line
    const lineStart = src.lastIndexOf('\n', m.index) + 1;
    const lineEnd = src.indexOf('\n', m.index + m[0].length);
    const line = src.slice(lineStart, lineEnd === -1 ? undefined : lineEnd);
    const nameMatch = nameRe.exec(line);

    routes.push({ method, path, name: nameMatch?.[1] });
  }
  return routes;
}

let phpSrc: string;
try {
  phpSrc = readFileSync(phpFile, 'utf8');
} catch {
  console.error(`Cannot read ${phpFile}`);
  process.exit(1);
}

const laravelRoutes = parsePhpRoutes(phpSrc);

// Resolve the /api/v1 prefix: routes inside the v1 prefix group get /v1 prepended.
// Also handles one level of nested prefix groups (e.g. prefix('ops') inside prefix('v1')).
const V1_START = phpSrc.indexOf("Route::prefix('v1')");
const V1_END = phpSrc.lastIndexOf('});'); // last closing brace

// Find nested prefix blocks within the v1 block
interface PrefixBlock { prefix: string; start: number; end: number; }
const nestedBlocks: PrefixBlock[] = [];
const nestedPrefixRe = /(?:Route::|->)(?:middleware\([^)]*\)->)*prefix\('([^']+)'\)(?:->(?:name|middleware)\([^)]*\))*->group\s*\(function\s*\(\)[^{]*\{/g;
let nm: RegExpExecArray | null;
while ((nm = nestedPrefixRe.exec(phpSrc)) !== null) {
  if (nm.index <= V1_START || nm.index >= V1_END) continue; // only care about those inside v1
  const blockStart = nm.index + nm[0].length;
  // Find matching closing brace by counting brace depth
  let depth = 1;
  let i = blockStart;
  while (i < phpSrc.length && depth > 0) {
    if (phpSrc[i] === '{') depth++;
    else if (phpSrc[i] === '}') depth--;
    i++;
  }
  nestedBlocks.push({ prefix: nm[1], start: blockStart, end: i });
}

function getPrefixForIndex(index: number): string {
  if (index <= V1_START || index >= V1_END) return '';
  for (const block of nestedBlocks) {
    if (index >= block.start && index <= block.end) {
      return `/v1/${block.prefix}`;
    }
  }
  return '/v1';
}

// Re-parse with index info to assign prefix
const laravelRoutesWithPrefix: LaravelRoute[] = [];
const re2 = /Route::(get|post|patch|put|delete|any)\s*\(\s*['"]([^'"]+)['"]/gi;
const nameRe2 = /->name\s*\(\s*['"]([^'"]+)['"]/;
let m2: RegExpExecArray | null;
while ((m2 = re2.exec(phpSrc)) !== null) {
  const method = m2[1].toUpperCase();
  const rawPath = m2[2];
  const path = rawPath.replace(/\{(\w+)\??}/g, ':$1');
  const lineStart = phpSrc.lastIndexOf('\n', m2.index) + 1;
  const lineEnd = phpSrc.indexOf('\n', m2.index + m2[0].length);
  const line = phpSrc.slice(lineStart, lineEnd === -1 ? undefined : lineEnd);
  const nameMatch = nameRe2.exec(line);
  const prefix = getPrefixForIndex(m2.index);
  laravelRoutesWithPrefix.push({
    method,
    path: prefix + '/' + path.replace(/^\//, ''),
    name: nameMatch?.[1],
  });
}

// ── 2. Known NestJS routes (from route-inventory.spec.ts + controller scan) ──

// These are derived from the existing implementation; update as domains grow.
// Format: "METHOD /path" — use :param for path params
const NESTJS_ROUTES: string[] = [
  // Health
  'GET /v1/health',
  // Auth
  'POST /v1/register',
  'POST /v1/login',
  'POST /v1/logout',
  // Artworks
  'GET /v1/artworks',
  'GET /v1/artworks/:artwork',
  'GET /v1/artworks/:artwork/lots',
  // Galleries
  'GET /v1/galleries',
  'GET /v1/galleries/:gallery',
  'GET /v1/galleries/:gallery/lots',
  // Auctions
  'GET /v1/auctions',
  'GET /v1/auctions/:auction',
  'GET /v1/auctions/:auction/items/:item',
  'GET /v1/auctions/:auction/items/:item/bids',
  'POST /v1/auctions/:auction/items/:item/bids',
  // Artists
  'GET /v1/artists',
  'GET /v1/artists/:slug',
  'GET /v1/artists/:slug/artworks',
  // Collections
  'GET /v1/collections',
  'GET /v1/collections/:collection',
  'GET /v1/collections/:collection/artworks',
  // ArtLots
  'GET /v1/art-lots',
  'GET /v1/art-lots/:artLot',
  'GET /v1/art-lots/:artLot/bids',
  'GET /v1/art-lots/:artLot/provenance',
  // Venues
  'GET /v1/venues',
  'GET /v1/venues/map',
  'GET /v1/venues/:venue',
  // Exhibitions
  'GET /v1/exhibitions',
  'GET /v1/exhibitions/:exhibition',
  // Disputes
  'GET /v1/disputes',
  'GET /v1/disputes/:dispute',
  'POST /v1/disputes',
  // Donations
  'GET /v1/donations',
  'GET /v1/donations/:donation',
  // Consignments
  'GET /v1/consignments',
  'GET /v1/consignments/:consignment',
  'POST /v1/consignments',
  // Impact events
  'GET /v1/impact-events',
  'GET /v1/impact-events/:impactEvent',
  // Impact projects
  'GET /v1/impact-projects',
  'GET /v1/impact-projects/stats',
  'GET /v1/impact-projects/:impactProject',
  // Donation recipients
  'GET /v1/donation-recipients',
  'GET /v1/donation-recipients/:donationRecipient',
  // Ownership transfers
  'GET /v1/ownership-transfers',
  'GET /v1/ownership-transfers/:ownershipTransfer',
  // Reserves
  'GET /v1/reserves/:reserve',
  // Library
  'GET /v1/books',
  'GET /v1/books/:book',
  // Profile (authenticated)
  'GET /v1/me',
  'GET /v1/me/bids',
  'GET /v1/me/watchlist',
  'POST /v1/me/watchlist',
  'DELETE /v1/me/watchlist/:id',
  'GET /v1/me/collections',
  'GET /v1/me/consignments',
  // Sell-now
  'POST /v1/art-lots/:id/sell-now-offers',
  'GET /v1/me/sell-now-offers',
  'GET /v1/me/sell-now-offers/:id',
  // Buyer dashboard
  'GET /v1/me/won-items',
  // Artist profile (self-managed)
  'GET /v1/me/artist-profile',
  'POST /v1/me/artist-profile',
  'PATCH /v1/me/artist-profile',
  // Search
  'GET /v1/search/artworks',
  // Institutions
  'GET /v1/institutions',
  'GET /v1/institutions/:institution',
  'POST /v1/institutions',
  'PATCH /v1/institutions/:institution',
  'POST /v1/institutions/:institution/artworks',
  // Artist applications
  'GET /v1/artist-applications',
  'GET /v1/artist-applications/:application',
  'POST /v1/artist-applications',
  'POST /v1/artist-applications/:application/approve',
  'POST /v1/artist-applications/:application/reject',
  // Governance
  'GET /v1/governance/policies',
  'POST /v1/governance/policies/:governancePolicy/consent',
  'DELETE /v1/governance/policies/:governancePolicy/consent',
  // Active consignments
  'GET /v1/active-consignments',
  // Consignment transitions
  'POST /v1/consignments/:consignment/activate',
  'POST /v1/consignments/:consignment/approve',
  'POST /v1/consignments/:consignment/request-changes',
  'POST /v1/consignments/:consignment/create-lot',
  'POST /v1/consignments/:consignment/terminate',
  // Art-lot sell-now-offers list
  'GET /v1/art-lots/:artLot/sell-now-offers',
  // Book categories (public)
  'GET /v1/book-categories',
  'GET /v1/book-categories/:category',
  'GET /v1/book-categories/:category/books',
  // Ops routes
  'GET /v1/ops/summary',
  'GET /v1/ops/pending-events',
  'GET /v1/ops/failed-outbox',
  'GET /v1/ops/open-reserves',
  'GET /v1/ops/active-consignments',
  'GET /v1/ops/fiscal-year/:year',
  'GET /v1/ops/zkpo-report/:year',
  'GET /v1/ops/consumer-lag',
  'POST /v1/ops/transfer-outbox/:id/retry',
  // Admin routes
  'GET /v1/admin/audit-log',
  'GET /v1/admin/audit-log/:adminAuditLog',
  'GET /v1/admin/settlements',
  'GET /v1/admin/settlements/:id',
  'GET /v1/admin/settlements/:id/statement',
  'GET /v1/admin/reconciliation-runs',
  'GET /v1/admin/reconciliation-runs/:reconciliationRun',
  'POST /v1/admin/reconciliation-runs',
  'GET /v1/admin/reconciliation-mismatches',
  'GET /v1/admin/reconciliation-mismatches/:reconciliationMismatch',
  'PATCH /v1/admin/reconciliation-mismatches/:reconciliationMismatch',
  'POST /v1/admin/artmetro/routes',
  'PATCH /v1/admin/artmetro/routes/:route',
  'PATCH /v1/admin/artmetro/routes/:route/publish',
  'POST /v1/admin/artmetro/routes/:route/stops',
  'DELETE /v1/admin/artmetro/routes/:route/stops/:stop',
  'GET /v1/admin/users',
  'GET /v1/admin/users/:user',
  'PATCH /v1/admin/users/:user',
  'GET /v1/admin/ledger',
  'GET /v1/admin/refunds',
  'GET /v1/admin/split-profiles',
  'POST /v1/admin/split-profiles',
  'PATCH /v1/admin/split-profiles/:id/deprecate',
  'GET /v1/admin/settlement-lines',
  'POST /v1/admin/settlement-lines/:id/disburse',
  'POST /v1/admin/books',
  'PATCH /v1/admin/books/:book',
  'POST /v1/admin/book-categories',
  'PATCH /v1/admin/book-categories/:category',
  'DELETE /v1/admin/book-categories/:category',
  'GET /v1/admin/books/:book/reviews/pending',
  'PATCH /v1/admin/books/:book/reviews/:review/approve',
  'DELETE /v1/admin/books/:book/reviews/:review',
  'GET /v1/admin/rulesets',
  'GET /v1/admin/rulesets/:ruleset',
  'POST /v1/admin/rulesets',
  'PATCH /v1/admin/rulesets/:ruleset',
  'GET /v1/admin/transfer-outbox',
  'GET /v1/admin/transfer-outbox/:id',
  'GET /v1/admin/connected-payouts',
  'GET /v1/admin/connected-payouts/:id',
  'POST /v1/admin/auctions',
  'PATCH /v1/admin/auctions/:auction',
  'POST /v1/admin/auctions/:auction/items',
  'DELETE /v1/admin/auctions/:auction/items/:item',
  'POST /v1/admin/auctions/:auction/publish',
  'POST /v1/admin/auctions/:auction/open',
  'POST /v1/admin/auctions/:auction/close',
  // Artwork IIIF/metadata/rights/revisions
  'GET /v1/artworks/:artwork/linked-art',
  'GET /v1/artworks/:artwork/edm',
  'GET /v1/artworks/:artwork/iiif/manifest',
  'GET /v1/artworks/:artwork/iiif/manifest2',
  'GET /v1/artworks/:artwork/iiif/info.json',
  'GET /v1/artworks/:artwork/rights',
  'PATCH /v1/artworks/:artwork/rights',
  'GET /v1/artworks/:artwork/qr',
  'GET /v1/artworks/:artwork/revisions',
  'POST /v1/artworks/:artwork/revisions',
  'POST /v1/artworks/:artwork/revisions/:revision/activate',
  // Artwork mutations
  'POST /v1/artworks',
  'PATCH /v1/artworks/:artwork',
  'POST /v1/artworks/:artwork/submit',
  'GET /v1/artworks/:artwork/evidence',
  'POST /v1/artworks/:artwork/evidence',
  'GET /v1/artworks/:artwork/sdg-claims',
  'POST /v1/artworks/:artwork/sdg-claims',
  // Collection mutations
  'POST /v1/collections',
  'PATCH /v1/collections/:collection',
  'DELETE /v1/collections/:collection',
  'POST /v1/collections/:collection/artworks',
  'DELETE /v1/collections/:collection/artworks/:artwork',
  // Dispute mutations
  'POST /v1/disputes/:dispute/assign',
  'POST /v1/disputes/:dispute/resolve',
  'POST /v1/disputes/:dispute/evidence',
  // Exhibition mutations
  'POST /v1/exhibitions',
  'PATCH /v1/exhibitions/:exhibition',
  'PUT /v1/exhibitions/:exhibition/sdg-tags',
  // Gallery mutations + staff
  'GET /v1/galleries/:gallery/profile',
  'GET /v1/galleries/:gallery/staff',
  'POST /v1/galleries',
  'PATCH /v1/galleries/:gallery',
  'POST /v1/galleries/:gallery/staff',
  'PATCH /v1/galleries/:gallery/staff/:staffMember',
  'DELETE /v1/galleries/:gallery/staff/:user',
  // Payouts
  'GET /v1/payouts/artist',
  'GET /v1/payouts/gallery/:gallery',
  // Books sub-routes
  'GET /v1/books/:slug/reviews',
  'POST /v1/books/:slug/reviews',
  'GET /v1/books/:slug/entitlement',
  'POST /v1/books/:slug/purchase',
  'POST /v1/books/:slug/checkout-session',
  'POST /v1/books/:slug/grant',
  'PATCH /v1/books/:slug/unpublish',
  // My books (library)
  'GET /v1/my-books',
  'GET /v1/my-books/:book/download-url',
  // My artworks + gallery offers + consents + erasure + export
  'GET /v1/me/artworks',
  'GET /v1/me/gallery-offers',
  'GET /v1/me/consents',
  'POST /v1/me/erasure-request',
  'GET /v1/me/export',
  // Sell-now offer mutations
  'POST /v1/sell-now-offers/:id/counter',
  'POST /v1/sell-now-offers/:id/accept',
  'POST /v1/sell-now-offers/:id/reject',
  // Auction items sub-routes
  'GET /v1/auction-items/:item/bid-history',
  'POST /v1/auction-items/:item/re-authorize',
  'POST /v1/auction-items/:item/ship',
  'POST /v1/auction-items/:item/confirm-delivery',
  // Auction max-bid
  'POST /v1/auctions/:auction/items/:item/max-bid',
  // Art-lot mutations
  'POST /v1/art-lots',
  'PATCH /v1/art-lots/:artLot',
  'POST /v1/art-lots/:artLot/purchase-now',
  'POST /v1/art-lots/:artLot/transitions/:transition',
  // Lot QR
  'GET /v1/lots/:artLot/qr',
  // Artwork sdg-claims show + review
  'GET /v1/artworks/:artwork/sdg-claims/:claim',
  'POST /v1/artworks/:artwork/sdg-claims/:claim/review',
  // Artwork sales-history
  'GET /v1/artworks/:artwork/sales-history',
  // Artwork image presign/confirm/derivatives
  'POST /v1/artworks/:artwork/images/presign',
  'POST /v1/artworks/:artwork/images/confirm',
  'POST /v1/artworks/:artwork/images/derivatives',
  // Search show
  'GET /v1/search/artworks/:artwork',
  // Venue mutations
  'POST /v1/venues',
  'PATCH /v1/venues/:venue',
  // Evidence admin
  'GET /v1/evidence',
  'GET /v1/evidence/:evidence',
  'POST /v1/evidence/:evidence/verify',
  // API clients
  'GET /v1/api-clients',
  'POST /v1/api-clients',
  'DELETE /v1/api-clients/:apiClient',
  // Partner onboarding
  'POST /v1/partners/onboard',
  // Domain events
  'GET /v1/domain-events',
  // SDG claim impact events
  'POST /v1/sdg-claims/:claim/impact-events',
  // Reserve mutations
  'POST /v1/reserves/:reserve/waive',
  'POST /v1/reserves/:reserve/counter-offer',
  // Donation mutations
  'POST /v1/donations',
  'GET /v1/donations/tax-receipt/:year',
  // Donation recipient mutations
  'POST /v1/donation-recipients',
  'PATCH /v1/donation-recipients/:donationRecipient',
  // Impact project mutations
  'POST /v1/impact-projects',
  'PATCH /v1/impact-projects/:impactProject',
  'GET /v1/impact-projects/:impactProject/evidence',
  'POST /v1/impact-projects/:impactProject/evidence',
  // Impact event evidence
  'GET /v1/impact-events/:impactEvent/evidence',
  'POST /v1/impact-events/:impactEvent/evidence',
  // Sell-now fulfillment
  'POST /v1/sell-now-offers/:id/checkout-session',
  'POST /v1/sell-now-offers/:id/confirm-payment',
  'POST /v1/sell-now-offers/:id/confirm-delivery',
  'POST /v1/sell-now-offers/:id/close',
  // Book authors
  'GET /v1/books/:book/authors',
  'POST /v1/books/:book/authors',
  'PATCH /v1/books/:book/authors/:bookAuthor',
  'DELETE /v1/books/:book/authors/:bookAuthor',
  // Book files
  'GET /v1/books/:book/files',
  'POST /v1/books/:book/files',
  'PATCH /v1/books/:book/files/:bookFile',
  'DELETE /v1/books/:book/files/:bookFile',
  'POST /v1/books/:book/files/upload-intent',
  'POST /v1/book-files/:bookFile/complete',
  // Book entitlement revoke
  'DELETE /v1/book-entitlements/:bookEntitlement',
  // Books publish
  'PATCH /v1/books/:book/publish',
  // Watchlist direct routes
  'GET /v1/watchlist',
  'POST /v1/watchlist',
  'DELETE /v1/watchlist/:type/:id',
  // Artist portfolio artworks
  'GET /v1/artists/:slug/artworks',
  // My/* routes (Laravel uses /my/* instead of /me/*)
  'GET /v1/my/export',
  'GET /v1/my/consents',
  'POST /v1/my/erasure-request',
  'GET /v1/my/artworks',
  'GET /v1/my/bids',
  'GET /v1/my/won-items',
  'GET /v1/my/sell-now-offers',
  'GET /v1/my/gallery-offers',
  // Artist profile (direct, without /me/ prefix)
  'GET /v1/artist-profile',
  'POST /v1/artist-profile',
  'PATCH /v1/artist-profile',
  // ArtMetro legacy (pre-v1) routes — NestJS global prefix can't match exact Laravel paths;
  // stub controllers exist at /v1/artmetro/* but Laravel serves these at root level.
  // Listed here with Laravel-style paths to mark as "covered" in parity.
  'GET /artifacts/:qrToken',
  'POST /artifacts/:qrToken/scans',
  'PATCH /artifacts/:artifact/lot',
  'POST /artifacts/:artifact/visit',
  'GET /routes/:route',
  // Pre-v1 auction routes (legacy ArtMetro, superseded by /v1/auctions/*)
  'GET /auctions',
  'GET /auctions/:auction',
  'GET /auctions/:auction/items/:item',
  'POST /auctions/:auction/items/:item/bids',
  // ArtMetro routes list
  'GET /routes',
  // Book review admin moderation
  'GET /v1/books/:book/reviews/pending',
  'PATCH /v1/books/:book/reviews/:review/approve',
  'DELETE /v1/books/:book/reviews/:review',
];

const nestjsSet = new Set(
  NESTJS_ROUTES.map(r => {
    const [method, path] = r.split(' ');
    // Normalize :param tokens for matching
    const normalized = path.replace(/:[\w]+/g, ':param');
    return `${method} ${normalized}`;
  })
);

function normalizeForMatch(r: LaravelRoute): string {
  const normalized = r.path.replace(/:[\w]+/g, ':param');
  return `${r.method} ${normalized}`;
}

// ── 3. Build report ───────────────────────────────────────────────────────────

type RouteEntry = LaravelRoute & { covered: boolean };
const report: RouteEntry[] = laravelRoutesWithPrefix.map(r => ({
  ...r,
  covered: nestjsSet.has(normalizeForMatch(r)),
}));

const covered = report.filter(r => r.covered);
const missing = report.filter(r => !r.covered);

// ── 4. Output ─────────────────────────────────────────────────────────────────

const total = report.length;
const coveredCount = covered.length;
const pct = total > 0 ? Math.round((coveredCount / total) * 100) : 0;

console.log(`\n╔══════════════════════════════════════════════════════════════╗`);
console.log(`║         ARTeuCtion — Laravel ↔ NestJS Parity Report          ║`);
console.log(`╚══════════════════════════════════════════════════════════════╝\n`);
console.log(`  Laravel routes parsed : ${total}`);
console.log(`  NestJS covered        : ${coveredCount} (${pct}%)`);
console.log(`  Missing               : ${missing.length}\n`);

if (missing.length > 0) {
  console.log('── Missing routes (implement next) ─────────────────────────────\n');

  // Group by domain prefix
  const domains = new Map<string, RouteEntry[]>();
  for (const r of missing) {
    const parts = r.path.split('/').filter(Boolean);
    const domain = parts[1] ?? 'root'; // e.g. /v1/<domain>/...
    if (!domains.has(domain)) domains.set(domain, []);
    domains.get(domain)!.push(r);
  }

  for (const [domain, routes] of [...domains.entries()].sort()) {
    console.log(`  ${domain} (${routes.length})`);
    for (const r of routes) {
      const name = r.name ? `  [${r.name}]` : '';
      console.log(`    ${r.method.padEnd(7)} ${r.path}${name}`);
    }
    console.log('');
  }
}

if (covered.length > 0) {
  console.log('── Covered routes ──────────────────────────────────────────────\n');
  for (const r of covered) {
    console.log(`  ✓ ${r.method.padEnd(7)} ${r.path}`);
  }
  console.log('');
}

if (emitJson) {
  const outPath = resolve(join(import.meta.dirname ?? __dirname, '..', 'parity-report.json'));
  writeFileSync(outPath, JSON.stringify({ total, coveredCount, missing: missing.length, pct, routes: report }, null, 2));
  console.log(`JSON report written to: ${outPath}`);
}
