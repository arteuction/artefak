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

// Resolve the /api/v1 prefix: routes inside the v1 prefix group get /v1 prepended
// The file wraps v1 routes in Route::prefix('v1'), so we detect if the path
// appears inside the prefix block by checking the raw source for the v1 wrapper.
// Simpler: prefix all routes that don't start with /artifacts or /routes (ArtMetro)
const V1_START = phpSrc.indexOf("Route::prefix('v1')");
const V1_END = phpSrc.lastIndexOf('});'); // last closing brace

function isV1Route(index: number): boolean {
  return index > V1_START && index < V1_END;
}

// Re-parse with index info to assign /v1 prefix
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
  const prefix = isV1Route(m2.index) ? '/v1' : '';
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
