/**
 * Phase 0.3U — LibraryController tests (public + auth endpoints)
 *
 * LB1  GET  /books               — 200 + pagination
 * LB2  GET  /books/my-book       — 200 with book data
 * LB3  GET  /books/missing-book  — 404
 * LB4  GET  /books/my-book/reviews — 200
 * LB5  POST /books/my-book/reviews (auth) — 201
 * LB6  GET  /books/my-book/entitlement (auth) — 200
 * LB7  POST /books/my-book/purchase (auth)    — 200
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication, NotFoundException } from '@nestjs/common';
import { PassportModule } from '@nestjs/passport';
import { JwtModule } from '@nestjs/jwt';
import { Strategy as JwtStrategy, ExtractJwt } from 'passport-jwt';
import { PassportStrategy } from '@nestjs/passport';
import { Injectable } from '@nestjs/common';
import { LibraryController } from './library.controller';
import { LibraryService } from './library.service';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { RolesGuard } from '../auth/roles.guard';
import jwt from 'jsonwebtoken';

const { sign } = jwt;

const TEST_SECRET = 'library-ctrl-test-secret';

const STUB_BOOK = {
  id: 1, slug: 'my-book', title: 'My Book', language: 'en',
  is_free: false, is_featured: false, status: 'published',
  short_description: 'A test book', created_at: new Date().toISOString(),
};

@Injectable()
class TestJwtStrategy extends PassportStrategy(JwtStrategy) {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), secretOrKey: TEST_SECRET });
  }
  validate(payload: { sub: number; role: string }) {
    return { id: payload.sub, role: payload.role };
  }
}

const mockLibraryService = {
  listBooks: async () => ({ data: [STUB_BOOK], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } }),
  getBook: async (slug: string) => {
    if (slug === 'missing-book') return null;
    return STUB_BOOK;
  },
  getBookReviews: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
  createReview: async () => ({ id: 1, rating: 5, comment: 'Great!', created_at: new Date().toISOString() }),
  getEntitlement: async () => ({ has_access: false }),
  purchaseBook: async () => ({ status: 'ok' }),
  checkoutSession: async () => ({ url: 'https://checkout.stripe.com/test' }),
  publishBook: async () => ({ status: 'ok' }),
  unpublishBook: async () => ({ status: 'ok' }),
  grantAccess: async () => ({ status: 'ok' }),
};

describe('Phase 0.3U — LibraryController', () => {
  let app: INestApplication;
  let userToken: string;

  beforeAll(async () => {
    userToken = sign({ sub: 1, role: 'buyer' }, TEST_SECRET, { expiresIn: '1h' });

    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: TEST_SECRET })],
      controllers: [LibraryController],
      providers: [
        TestJwtStrategy,
        { provide: LibraryService, useValue: mockLibraryService },
      ],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await (app as NestFastifyApplication).getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('LB1: GET /books returns 200 with pagination', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/books' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('slug');
  });

  it('LB2: GET /books/my-book returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/books/my-book' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.slug).toBe('my-book');
  });

  it('LB3: GET /books/missing-book returns 404', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/books/missing-book' });
    expect(res.statusCode).toBe(404);
  });

  it('LB4: GET /books/my-book/reviews returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/books/my-book/reviews' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
  });

  it('LB5: POST /books/my-book/reviews (auth) returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'POST',
      url: '/api/v1/books/my-book/reviews',
      headers: { authorization: `Bearer ${userToken}` },
      payload: { rating: 5, comment: 'Excellent' },
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('rating');
  });

  it('LB6: GET /books/my-book/entitlement (auth) returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'GET',
      url: '/api/v1/books/my-book/entitlement',
      headers: { authorization: `Bearer ${userToken}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('has_access');
  });

  it('LB7: POST /books/my-book/purchase (auth) returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'POST',
      url: '/api/v1/books/my-book/purchase',
      headers: { authorization: `Bearer ${userToken}` },
      payload: {},
    });
    expect(res.statusCode).toBe(200);
  });

  it('LB8: POST /books/my-book/reviews without token returns 401', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'POST',
      url: '/api/v1/books/my-book/reviews',
      payload: { rating: 4 },
    });
    expect(res.statusCode).toBe(401);
  });
});
