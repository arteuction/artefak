/**
 * Phase 0.3B — Controller guard integration tests
 *
 * Verifies that every protected route returns:
 *   401 Unauthorized   — when no JWT is supplied
 *   403 Forbidden      — when a JWT is supplied but the role is insufficient
 *   2xx               — when the correct role is present (smoke-test only)
 *
 * Routes under test (mirrors Laravel routes/api.php auth:sanctum groups):
 *   GET  /api/v1/me                               — any authenticated user
 *   POST /api/v1/auctions/:a/items/:i/bids        — any authenticated user
 *   PATCH /api/v1/books/:slug/publish             — admin | operator only
 *
 * These tests use the NestJS HTTP-adapter supertest helper — no real DB required
 * for the 401/403 cases (auth guard rejects before any DB call).
 * The 2xx smoke cases are skipped unless HAS_DB=true.
 */

import { Test, TestingModule } from '@nestjs/testing';
import {
  NestFastifyApplication,
} from '@nestjs/platform-fastify';
import { FastifyAdapter } from '@nestjs/platform-fastify';
import { PassportModule } from '@nestjs/passport';
import { JwtModule, JwtService } from '@nestjs/jwt';

import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { ProfileController } from './profile/profile.controller';
import { ProfileService } from './profile/profile.service';
import { GovernanceService } from './governance/governance.service';
import { BidController } from './auction/bid.controller';
import { LibraryController } from './library/library.controller';
import { LibraryService } from './library/library.service';
import { BidConcurrencyService } from './auction/bid-concurrency.service';
import { JwtAuthGuard } from './auth/jwt-auth.guard';
import { RolesGuard } from './auth/roles.guard';
import { JwtPayload, AuthenticatedUser } from './auth/auth.types';
import { PrismaService } from './prisma.service';

// ── Test-only JWT strategy — skips DB lookup, returns payload directly ────────
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({
      jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(),
      ignoreExpiration: false,
      secretOrKey: JWT_SECRET,
    });
  }

  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return {
      id: payload.sub,
      email: payload.email,
      role: payload.role as AuthenticatedUser['role'],
      emailVerifiedAt: null,
    };
  }
}

const JWT_SECRET = 'test-guard-secret';
const HAS_DB = !!process.env.DATABASE_URL;
const describeWithDb = HAS_DB ? describe : describe.skip;

// ── helpers ──────────────────────────────────────────────────────────────────

function makeToken(jwt: JwtService, role: string, userId = 999): string {
  return jwt.sign({ sub: userId, email: `${role}@test.local`, role });
}

// ── test suite ────────────────────────────────────────────────────────────────

describe('Phase 0.3B — Controller guards', () => {
  let app: NestFastifyApplication;
  let jwt: JwtService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [
        PassportModule.register({ defaultStrategy: 'jwt' }),
        JwtModule.register({ secret: JWT_SECRET, signOptions: { expiresIn: '15m' } }),
      ],
      controllers: [ProfileController, BidController, LibraryController],
      providers: [
        TestJwtStrategy,
        {
          provide: RolesGuard,
          useClass: RolesGuard,
        },
        {
          provide: LibraryService,
          useValue: {
            listBooks: async () => ({ data: [], meta: {} }),
            getBook: async () => null,
            publishBook: async (slug: string) => ({ slug, status: 'published' }),
          },
        },
        {
          provide: BidConcurrencyService,
          useValue: {
            placeBid: async () => ({ bidId: BigInt(1), amountCents: BigInt(10000), status: 'accepted' }),
          },
        },
        {
          provide: ProfileService,
          useValue: {
            getUserBids: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
          },
        },
        {
          provide: GovernanceService,
          useValue: { myConsents: async () => [], requestErasure: async () => ({ status: 'scheduled' }), exportData: async () => ({ status: 'queued' }) },
        },
      ],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(
      new FastifyAdapter(),
    );
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();

    jwt = module.get(JwtService);
  });

  afterAll(async () => {
    await app.close();
  });

  // ── G1-G3: GET /api/v1/me ──────────────────────────────────────────────────

  it('G1: GET /me returns 401 without token', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/me' });
    expect(res.statusCode).toBe(401);
  });

  it('G2: GET /me returns 401 with malformed token', async () => {
    const res = await app.inject({
      method: 'GET',
      url: '/api/v1/me',
      headers: { Authorization: 'Bearer not.a.jwt' },
    });
    expect(res.statusCode).toBe(401);
  });

  it('G3: GET /me returns 200 with valid buyer token', async () => {
    const token = makeToken(jwt, 'buyer');
    const res = await app.inject({
      method: 'GET',
      url: '/api/v1/me',
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ email: 'buyer@test.local', role: 'buyer' });
  });

  // ── G4-G6: POST /api/v1/auctions/:a/items/:i/bids ────────────────────────

  it('G4: POST bid returns 401 without token', async () => {
    const res = await app.inject({
      method: 'POST',
      url: '/api/v1/auctions/1/items/1/bids',
      payload: { amount_cents: 10000 },
    });
    expect(res.statusCode).toBe(401);
  });

  it('G5: POST bid returns 201 with valid buyer token', async () => {
    const token = makeToken(jwt, 'buyer');
    const res = await app.inject({
      method: 'POST',
      url: '/api/v1/auctions/1/items/1/bids',
      headers: { Authorization: `Bearer ${token}` },
      payload: { amount_cents: 10000 },
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ status: 'accepted', amount_cents: '10000' });
  });

  it('G6: POST bid returns 400 when amount_cents missing', async () => {
    const token = makeToken(jwt, 'buyer');
    const res = await app.inject({
      method: 'POST',
      url: '/api/v1/auctions/1/items/1/bids',
      headers: { Authorization: `Bearer ${token}` },
      payload: {},
    });
    expect(res.statusCode).toBe(400);
  });

  // ── G7-G11: PATCH /api/v1/books/:slug/publish ─────────────────────────────

  it('G7: PATCH publish returns 401 without token', async () => {
    const res = await app.inject({
      method: 'PATCH',
      url: '/api/v1/books/some-book/publish',
    });
    expect(res.statusCode).toBe(401);
  });

  it('G8: PATCH publish returns 403 for buyer role', async () => {
    const token = makeToken(jwt, 'buyer');
    const res = await app.inject({
      method: 'PATCH',
      url: '/api/v1/books/some-book/publish',
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(403);
  });

  it('G9: PATCH publish returns 403 for artist role', async () => {
    const token = makeToken(jwt, 'artist');
    const res = await app.inject({
      method: 'PATCH',
      url: '/api/v1/books/some-book/publish',
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(403);
  });

  it('G10: PATCH publish returns 200 for admin role', async () => {
    const token = makeToken(jwt, 'admin');
    const res = await app.inject({
      method: 'PATCH',
      url: '/api/v1/books/some-book/publish',
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
  });

  it('G11: PATCH publish returns 200 for operator role', async () => {
    const token = makeToken(jwt, 'operator');
    const res = await app.inject({
      method: 'PATCH',
      url: '/api/v1/books/some-book/publish',
      headers: { Authorization: `Bearer ${token}` },
    });
    expect(res.statusCode).toBe(200);
  });

  // ── G12: Public routes remain unauthenticated ──────────────────────────────

  it('G12: GET /books returns 200 without token (public route)', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/books' });
    expect(res.statusCode).toBe(200);
  });
});
