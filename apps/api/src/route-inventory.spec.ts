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
  // $transaction receives an array of Promises when called with array style
  $transaction: async (ops: Promise<unknown>[]) => Promise.all(ops),
  auction: { findMany: emptyFindMany, count: emptyCount, findUnique: notFound, findFirst: notFound },
  auctionItem: { findFirst: notFound },
  bid: { findMany: emptyFindMany, count: emptyCount },
  artworks: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  art_lots: { findMany: emptyFindMany },
  galleries: { findMany: emptyFindMany, count: emptyCount, findUnique: notFound },
  artist_profiles: { findMany: emptyFindMany, count: emptyCount, findUnique: notFound },
  collections: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  collection_artworks: { findMany: emptyFindMany },
  watchlist: { findMany: emptyFindMany },
  sell_now_offers: { findMany: emptyFindMany, count: emptyCount },
  consignments: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Library
  book: { findMany: async () => [], count: emptyCount, findUnique: notFound },
  // Venues
  venues: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Exhibitions
  exhibitions: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Disputes
  disputes: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Donations
  donations: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Impact
  impact_events: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Donation recipients
  donation_recipients: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
  // Impact projects
  impact_projects: { findMany: emptyFindMany, count: emptyCount, findFirst: notFound },
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
];

const AUTH_ROUTES = [
  'GET /api/v1/me',
  'GET /api/v1/me/bids',
  'GET /api/v1/me/watchlist',
  'GET /api/v1/me/collections',
  'GET /api/v1/me/consignments',
  'GET /api/v1/me/sell-now-offers',
  'GET /api/v1/disputes',
  'GET /api/v1/donations',
  'GET /api/v1/consignments',
];

describe('Phase 0.3O — Route inventory', () => {
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
