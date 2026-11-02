/**
 * Phase 0.3U — BookAuthorsController tests
 *
 * BA1  GET  /books/my-book/authors          — 200
 * BA2  POST /books/my-book/authors (auth)   — 201
 * BA3  PATCH /books/my-book/authors/1 (auth) — 200
 * BA4  DELETE /books/my-book/authors/1 (auth) — 200 { deleted: true }
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { BookAuthorsController } from './book-authors.controller';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const mockJwtGuard = { canActivate: () => true };

describe('Phase 0.3U — BookAuthorsController', () => {
  let app: INestApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [BookAuthorsController],
    })
      .overrideGuard(JwtAuthGuard)
      .useValue(mockJwtGuard)
      .compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await (app as NestFastifyApplication).getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('BA1: GET /books/my-book/authors returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/books/my-book/authors' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
  });

  it('BA2: POST /books/my-book/authors returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/books/my-book/authors', payload: { name: 'Jane Doe' } });
    expect(res.statusCode).toBe(201);
  });

  it('BA3: PATCH /books/my-book/authors/1 returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'PATCH', url: '/api/v1/books/my-book/authors/1', payload: { name: 'Updated Name' } });
    expect(res.statusCode).toBe(200);
  });

  it('BA4: DELETE /books/my-book/authors/1 returns 200 with deleted flag', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'DELETE', url: '/api/v1/books/my-book/authors/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.deleted).toBe(true);
  });
});
