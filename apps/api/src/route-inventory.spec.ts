/**
 * Phase 0.3K — Route inventory spec
 *
 * Boots the full AppModule (with DB calls stubbed via PrismaService mock)
 * and verifies every expected public and authenticated route is REGISTERED
 * (responds with a non-404 status code).
 *
 * This is a contract test: a missing route returns 404 and fails the spec.
 * It does NOT test response bodies — those are covered per-controller.
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable, NotFoundException } from '@nestjs/common';

import { AppModule } from './app.module';
import { PrismaService } from './prisma.service';
import { JwtStrategy } from './auth/jwt.strategy';
import type { AuthenticatedUser, JwtPayload } from './auth/auth.types';

const JWT_SECRET = 'test-inventory-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

// ── PrismaService stub — returns safe empty values for all models ──────────────

const emptyPage = { data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } };

function emptyFindMany() { return Promise.resolve([]); }
function emptyCount() { return Promise.resolve(0); }
function notFound() { return Promise.reject(new NotFoundException('stub')); }

const prismaMock = {
  $queryRaw: async () => [{ 1: 1 }],
  $executeRaw: async () => 1,
  // $transaction receives an array of Promises when called with array style
  $transaction: async (ops: Promise<unknown>[]) => Promise.all(ops),
  auction: { findMany: emptyFindMany, count: emptyCount, findUnique: notFound, findFirst: notFound },
  auctionItem: { findFirst: async () => ({ id: BigInt(1), auction_id: BigInt(1), status: 'sold', fulfillment_status: null }), findMany: emptyFindMany, count: emptyCount, update: async () => ({ id: BigInt(1), fulfillment_status: 'shipped', updated_at: null }) },
  bid: { findMany: emptyFindMany, count: emptyCount },
  artworks: { findMany: emptyFindMany, count: emptyCount, findFirst: async () => ({ id: BigInt(1), title: 'Test', slug: '1', user_id: BigInt(1), status: 'published', deleted_at: null }), findUnique: async () => ({ id: BigInt(1), rights_statement: null, license_spdx: null, provenance: null }), create: async () => ({ id: BigInt(1) }), update: async () => ({ id: BigInt(1) }) },
  art_lots: { findMany: emptyFindMany },
  galleries: { findMany: emptyFindMany, count: emptyCount, findUnique: async () => ({ id: BigInt(1), name: 'Test Gallery', slug: '1', type: 'private', status: 'active', gallery_staff: [] }), create: async () => ({ id: BigInt(1) }), update: async () => ({ id: BigInt(1) }) },
  gallery_staff: { findMany: emptyFindMany, findFirst: notFound, create: async () => ({ id: BigInt(1) }), update: async () => ({ id: BigInt(1) }), delete: async () => ({}) },
  artist_profiles: { findMany: emptyFindMany, count: emptyCount, findUnique: notFound, findFirst: async () => ({ id: 1, display_name: 'Test Artist', slug: 'test-artist', bio: null, website: null, instagram_handle: null, status: 'active', terms_accepted_at: null, created_at: new Date() }), create: async () => ({ id: 1 }), update: async () => ({ id: 1 }) },
  collections: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  collection_artworks: { findMany: emptyFindMany, aggregate: async () => ({ _max: { position: 0 } }), create: async () => ({ id: 1 }), findFirst: notFound, delete: async () => ({}) },
  watchlist: { findMany: emptyFindMany, upsert: async () => ({ id: BigInt(1), subject_type: 'artwork', subject_id: BigInt(1), created_at: new Date() }), findFirst: async () => ({ id: BigInt(1), user_id: BigInt(1) }), delete: async () => ({}) },
  sell_now_offers: { findMany: emptyFindMany, count: emptyCount },
  consignments: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Library
  book: { findMany: async () => [], count: emptyCount, findUnique: notFound },
  book_entitlements: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Venues
  venues: { findMany: emptyFindMany, count: emptyCount, findFirst: async () => ({ id: BigInt(1), name: 'Test Venue', slug: '1', address: null, latitude: null, longitude: null, is_active: true }), update: async () => ({ id: BigInt(1) }), create: async () => ({ id: BigInt(1) }) },
  // Exhibitions
  exhibitions: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound, create: async () => ({ id: BigInt(1) }), update: async () => ({ id: BigInt(1) }) },
  artmetro_exhibition_sdg: { deleteMany: async () => ({ count: 0 }), createMany: async () => ({ count: 0 }) },
  // Disputes
  disputes: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound, create: async () => ({ id: BigInt(1) }), update: async () => ({ id: BigInt(1) }) },
  dispute_escalations: { create: async () => ({ id: BigInt(1) }) },
  // Donations
  donations: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound, create: async () => ({ id: BigInt(1), donated_cents: BigInt(100), currency: 'EUR', type: 'donation', status: 'pending', eligibility_basis: 'voluntary', deduction_bps: 0, created_at: new Date() }) },
  // Impact
  impact_events: { findMany: emptyFindMany, count: emptyCount, findFirst: async () => ({ id: BigInt(1), sdg_number: 13, metric: 'CO2_tons', magnitude: BigInt(10), currency: 'EUR', type: 'event', created_at: new Date() }) },
  impact_evidence: { findMany: emptyFindMany, create: async () => ({ id: BigInt(1) }) },
  // Donation recipients
  donation_recipients: { findMany: emptyFindMany, count: emptyCount, findFirst: async () => ({ id: BigInt(1), name: 'Test Recipient' }), create: async () => ({ id: BigInt(1), name: 'Test', legal_type: 'ngo', eligibility_basis: 'art31', deduction_bps: 0, status: 'active', created_at: new Date() }), update: async () => ({ id: BigInt(1), name: 'Test', status: 'active', updated_at: new Date() }) },
  // Impact projects
  impact_projects: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound, create: async () => ({ id: BigInt(1), title: 'T', slug: 's', sdg_number: 15, funding_target_cents: BigInt(1000), currency: 'EUR', status: 'active', created_at: new Date() }), update: async () => ({ id: BigInt(1), title: 'T', slug: 's', status: 'funded', updated_at: new Date() }) },
  // Domain events (ops)
  domain_events: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound, update: async () => ({ id: BigInt(1) }) },
  // Ownership transfers
  ownership_transfers: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Reserves
  reserves: { findMany: emptyFindMany, findFirst: notFound, create: async () => ({ id: BigInt(1) }), update: async () => ({ id: BigInt(1) }) },
  // Institutions
  institutions: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  institution_artworks: { create: async () => ({ id: 1 }) },
  // Artist applications
  artist_applications: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Users (needed for auth service email verification)
  user: { findUnique: async () => ({ id: BigInt(1), email: 'test@example.com', email_verified_at: null }), findMany: emptyFindMany, count: emptyCount, update: async () => ({ id: BigInt(1) }) },
  // Artwork sub-resources
  artwork_evidence: { findMany: emptyFindMany, create: async () => ({ id: 1 }) },
  artwork_sdg_claims: { findMany: emptyFindMany, findFirst: async () => ({ id: 1, sdg_number: 1, status: 'pending' }), create: async () => ({ id: 1 }), update: async () => ({ id: 1, status: 'approved' }) },
  artwork_revisions: { findMany: emptyFindMany, findFirst: notFound, create: async () => ({ id: BigInt(1) }), update: async () => ({ id: BigInt(1) }) },
  // Governance
  governance_policies: { findMany: emptyFindMany, findFirst: notFound },
  user_consents: { findMany: emptyFindMany, findFirst: notFound, upsert: async () => ({ id: 1 }), update: async () => ({ id: 1 }) },
};

// ── helpers ───────────────────────────────────────────────────────────────────

import jwt from 'jsonwebtoken';
const { sign } = jwt;

function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'test@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

// ── suite ──────────────────────────────────────────────────────────────────────

const PUBLIC_ROUTES = [
  'GET /api/v1/health',
  'GET /api/v1/auctions',
  'GET /api/v1/artworks',
  'GET /api/v1/galleries',
  'GET /api/v1/artists',
  'GET /api/v1/collections',
  'GET /api/v1/art-lots',
  'GET /api/v1/venues',
  'GET /api/v1/venues/map',
  'GET /api/v1/exhibitions',
  'GET /api/v1/impact-events',
  'GET /api/v1/donation-recipients',
  'GET /api/v1/impact-projects',
  'GET /api/v1/impact-projects/stats',
  'GET /api/v1/search/artworks',
  // Artwork metadata routes (public)
  'GET /api/v1/artworks/1/linked-art',
  'GET /api/v1/artworks/1/edm',
  'GET /api/v1/artworks/1/iiif/manifest',
  'GET /api/v1/artworks/1/rights',
  'GET /api/v1/artworks/1/qr',
  'GET /api/v1/artworks/1/revisions',
  // Gallery sub-routes (public)
  'GET /api/v1/galleries/1/profile',
  'GET /api/v1/galleries/1/staff',
  // Institutions
  'GET /api/v1/institutions',
  // Governance (public policy list)
  'GET /api/v1/governance/policies',
  // Active consignments (public)
  'GET /api/v1/active-consignments',
  // Book categories (public)
  'GET /api/v1/book-categories',
  // Book reviews (public)
  'GET /api/v1/books/test/reviews',
  // Auction items bid-history (public)
  'GET /api/v1/auction-items/1/bid-history',
  // Search show (public)
  'GET /api/v1/search/artworks/1',
  // Artwork sdg-claim show (public)
  'GET /api/v1/artworks/1/sdg-claims/1',
  // Artwork sales-history (public)
  'GET /api/v1/artworks/1/sales-history',
  // Impact events/projects evidence (public)
  'GET /api/v1/impact-events/1/evidence',
  'GET /api/v1/impact-projects/1/evidence',
  // Lot QR (public)
  'GET /api/v1/lots/1/qr',
  // ArtMetro legacy stubs (public)
  'GET /api/v1/artmetro/artifacts/test-token',
  // Artwork image URL (public, returns 403 for non-active but not 404)
  'GET /api/v1/artworks/1/images/url',
];

const AUTH_ROUTES = [
  'GET /api/v1/me',
  'GET /api/v1/me/bids',
  'GET /api/v1/me/watchlist',
  'GET /api/v1/me/collections',
  'GET /api/v1/me/consignments',
  'GET /api/v1/me/sell-now-offers',
  'GET /api/v1/me/won-items',
  'GET /api/v1/me/artworks',
  'GET /api/v1/me/gallery-offers',
  'GET /api/v1/me/consents',
  'GET /api/v1/me/export',
  'GET /api/v1/disputes',
  'GET /api/v1/donations',
  'GET /api/v1/consignments',
  'GET /api/v1/ownership-transfers',
  // Artist applications (authenticated)
  'GET /api/v1/artist-applications',
  // My books
  'GET /api/v1/my-books',
  // Payouts
  'GET /api/v1/payouts/artist',
  // Evidence, domain-events, book sub-routes, payouts
  'GET /api/v1/evidence',
  'GET /api/v1/domain-events',
  'GET /api/v1/books/test/authors',
  'GET /api/v1/books/test/files',
  'GET /api/v1/payouts/gallery/1',
  // Ops (stub)
  'GET /api/v1/ops/summary',
  'GET /api/v1/ops/pending-events',
  'GET /api/v1/ops/failed-outbox',
  'GET /api/v1/ops/open-reserves',
  'GET /api/v1/ops/active-consignments',
  // Admin (stub)
  'GET /api/v1/admin/audit-log',
  'GET /api/v1/admin/settlements',
  'GET /api/v1/admin/users',
  'GET /api/v1/admin/ledger',
  // Watchlist direct routes
  'GET /api/v1/watchlist',
  // My/* routes (Laravel path aliases)
  'GET /api/v1/my/export',
  'GET /api/v1/my/consents',
  'GET /api/v1/my/artworks',
  'GET /api/v1/my/bids',
  'GET /api/v1/my/won-items',
  // Artist profile direct routes
  'GET /api/v1/artist-profile',
  // Book review admin moderation
  'GET /api/v1/books/test/reviews/pending',
];

describe('Phase 0.3T — Route inventory', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [
        AppModule,
        PassportModule,
        JwtModule.register({ secret: JWT_SECRET }),
      ],
    })
      .overrideProvider(PrismaService)
      .useValue(prismaMock)
      .overrideProvider(JwtStrategy)
      .useClass(TestJwtStrategy)
      .compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  }, 120000);

  afterAll(async () => {
    await app.close();
  });

  it.each(PUBLIC_ROUTES)('RI-public: %s is registered (not 404)', async (route) => {
    const [method, url] = route.split(' ');
    const res = await app.inject({ method: method as 'GET', url });
    expect(res.statusCode).not.toBe(404);
  });

  it.each(AUTH_ROUTES)('RI-auth: %s is registered (not 404)', async (route) => {
    const [method, url] = route.split(' ');
    const token = makeToken('user');
    const res = await app.inject({ method: method as 'GET', url, headers: { authorization: `Bearer ${token}` } });
    expect(res.statusCode).not.toBe(404);
  });
});
