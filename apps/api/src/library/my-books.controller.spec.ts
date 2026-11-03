/**
 * Phase 0.3U — MyBooksController tests
 *
 * MB1  GET /my-books                    — 200 + pagination (auth)
 * MB2  GET /my-books/:book/download-url — 200 (auth)
 * MB3  GET /my-books without token      — 401
 * MB4  GET /my-books/not-found/download-url — 404 (book not entitled)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { PassportModule } from '@nestjs/passport';
import { JwtModule } from '@nestjs/jwt';
import { Strategy as JwtStrategy, ExtractJwt } from 'passport-jwt';
import { PassportStrategy } from '@nestjs/passport';
import { Injectable } from '@nestjs/common';
import { MyBooksController } from './my-books.controller';
import { LibraryService } from './library.service';
import jwt from 'jsonwebtoken';

const { sign } = jwt;

const TEST_SECRET = 'my-books-test-secret';

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
  myBooks: async (_userId: number, page = 1, perPage = 20) => ({
    data: [],
    meta: { current_page: page, last_page: 1, per_page: perPage, total: 0 },
  }),
  bookDownloadUrl: async (book: string, _userId: number) => {
    if (book === 'not-found') { const { NotFoundException } = await import('@nestjs/common'); throw new NotFoundException(`Book "${book}" not found`); }
    return { url: 'https://cdn.example.com/book.pdf', expires_at: new Date().toISOString() };
  },
};

describe('Phase 0.3U — MyBooksController', () => {
  let app: INestApplication;
  let userToken: string;

  beforeAll(async () => {
    userToken = sign({ sub: 1, role: 'buyer' }, TEST_SECRET, { expiresIn: '1h' });

    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: TEST_SECRET })],
      controllers: [MyBooksController],
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

  it('MB1: GET /my-books returns 200 with pagination', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'GET',
      url: '/api/v1/my-books',
      headers: { authorization: `Bearer ${userToken}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
  });

  it('MB2: GET /my-books/my-book/download-url returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'GET',
      url: '/api/v1/my-books/my-book/download-url',
      headers: { authorization: `Bearer ${userToken}` },
    });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('url');
  });

  it('MB3: GET /my-books without token returns 401', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/my-books' });
    expect(res.statusCode).toBe(401);
  });

  it('MB4: GET /my-books/not-found/download-url returns 404', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'GET',
      url: '/api/v1/my-books/not-found/download-url',
      headers: { authorization: `Bearer ${userToken}` },
    });
    expect(res.statusCode).toBe(404);
  });
});
