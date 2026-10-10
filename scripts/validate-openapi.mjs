#!/usr/bin/env node
/**
 * Phase 121 — OpenAPI spec validator.
 *
 * Validates the exported openapi.json against a checklist of required paths
 * and schema conventions. Run in CI after `php artisan scramble:export`.
 *
 * Usage:
 *   node scripts/validate-openapi.mjs [path/to/openapi.json]
 */

import { readFileSync } from 'fs';

const specPath = process.argv[2] ?? 'resources/js/api/openapi.json';

let spec;
try {
    spec = JSON.parse(readFileSync(specPath, 'utf8'));
} catch (e) {
    console.error(`✗ Cannot read spec at ${specPath}: ${e.message}`);
    process.exit(1);
}

const paths  = Object.keys(spec.paths ?? {});
const errors = [];
const warns  = [];

// ── Required paths ────────────────────────────────────────────────────────────

const required = [
    // Auth
    'POST /auth/login',
    'POST /auth/register',
    'GET /auth/me',
    // Artworks
    'GET /artworks',
    'POST /artworks',
    'GET /artworks/{artwork}',
    // Artists
    'GET /artists/{slug}',
    // Auctions
    'GET /auctions',
    'GET /auctions/{auction}',
    // SellNow
    'POST /art-lots/{artLot}/sell-now-offers',
    'GET /art-lots/{artLot}/sell-now-offers',
    'POST /sell-now-offers/{offer}/counter',
    'POST /sell-now-offers/{offer}/accept',
    'POST /sell-now-offers/{offer}/reject',
    'POST /sell-now-offers/{offer}/checkout-session',
    // Library
    'GET /books',
    'GET /books/{book}',
    'POST /books/{book}/purchase',
    'POST /books/{book}/checkout-session',
    'GET /my-books/{book}/download-url',
    // My resources
    'GET /my/sell-now-offers',
    'GET /my/gallery-offers',
    // Fulfillment
    'POST /sell-now-offers/{offer}/confirm-payment',
    'POST /sell-now-offers/{offer}/confirm-delivery',
    'POST /sell-now-offers/{offer}/close',
];

for (const entry of required) {
    const [method, rawPath] = entry.split(' ');
    const fullPath = `/api/v1${rawPath}`;
    const pathInSpec = paths.find(p => p === fullPath);
    if (!pathInSpec) {
        errors.push(`Missing required path: ${method} ${fullPath}`);
        continue;
    }
    const op = spec.paths[fullPath]?.[method.toLowerCase()];
    if (!op) {
        errors.push(`Missing method ${method} on ${fullPath}`);
    }
}

// ── Schema conventions ────────────────────────────────────────────────────────

// Every path should have at least one response defined
for (const [path, methods] of Object.entries(spec.paths ?? {})) {
    for (const [method, op] of Object.entries(methods)) {
        if (typeof op !== 'object' || !op.responses) {
            warns.push(`No responses defined for ${method.toUpperCase()} ${path}`);
        }
    }
}

// Info block
if (!spec.info?.title) errors.push('Missing info.title');
if (!spec.info?.version) errors.push('Missing info.version');
if (!spec.openapi?.startsWith('3.')) errors.push('Not OpenAPI 3.x');

// ── Report ────────────────────────────────────────────────────────────────────

const total = errors.length + warns.length;
for (const e of errors) console.error(`✗ ${e}`);
for (const w of warns)  console.warn(`! ${w}`);

if (total === 0) {
    console.log(`✓ OpenAPI spec valid — ${paths.length} paths, all required endpoints present`);
} else {
    console.log(`\n${errors.length} error(s), ${warns.length} warning(s) in ${specPath}`);
}

process.exit(errors.length > 0 ? 1 : 0);
