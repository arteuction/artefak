/**
 * Phase 0.3U — BookFilesController + BookFileCompleteController tests
 *
 * BF1  GET  /books/my-book/files              — 200
 * BF2  POST /books/my-book/files (auth)       — 201
 * BF3  POST /books/my-book/files/upload-intent — 200 with upload_url
 * BF4  PATCH /books/my-book/files/1 (auth)    — 200
 * BF5  DELETE /books/my-book/files/1 (auth)   — 200 { deleted: true }
 * BF6  POST /book-files/1/complete (auth)     — 200 { confirmed: true }
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { BookFilesController, BookFileCompleteController } from './book-files.controller';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const mockJwtGuard = { canActivate: () => true };

describe('Phase 0.3U — BookFilesController', () => {
  let app: INestApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [BookFilesController, BookFileCompleteController],
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

  it('BF1: GET /books/my-book/files returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'GET', url: '/api/v1/books/my-book/files' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
  });

  it('BF2: POST /books/my-book/files returns 201', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/books/my-book/files', payload: { filename: 'book.pdf' } });
    expect(res.statusCode).toBe(201);
  });

  it('BF3: POST /books/my-book/files/upload-intent returns 200 with upload_url', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/books/my-book/files/upload-intent', payload: { filename: 'book.pdf', content_type: 'application/pdf' } });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('upload_url');
    expect(body).toHaveProperty('key');
  });

  it('BF4: PATCH /books/my-book/files/1 returns 200', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'PATCH', url: '/api/v1/books/my-book/files/1', payload: { label: 'Updated' } });
    expect(res.statusCode).toBe(200);
  });

  it('BF5: DELETE /books/my-book/files/1 returns 200 with deleted flag', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'DELETE', url: '/api/v1/books/my-book/files/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.deleted).toBe(true);
  });

  it('BF6: POST /book-files/1/complete returns 200 with confirmed flag', async () => {
    const res = await (app as NestFastifyApplication).inject({ method: 'POST', url: '/api/v1/book-files/1/complete', payload: {} });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.confirmed).toBe(true);
  });
});
