/**
 * Phase 0.3R — BookCategory route tests
 *
 * BC1  GET /book-categories — 200 empty list
 * BC2  GET /book-categories/:id — 404 (stub, no DB table)
 * BC3  GET /book-categories/:id/books — 404 (stub, no DB table)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { BookCategoryController } from './book-category.controller';
import { BookCategoryService } from './book-category.service';

const mockService = {
  listCategories: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
  getCategory: async (id: string) => { throw new NotFoundException(`Category ${id} not found`); },
  getCategoryBooks: async (id: string) => { throw new NotFoundException(`Category ${id} not found`); },
};

describe('Phase 0.3R — BookCategory controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [BookCategoryController],
      providers: [{ provide: BookCategoryService, useValue: mockService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('BC1: GET /book-categories returns 200 with empty list', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/book-categories' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body.data)).toBe(true);
    expect(body.data).toHaveLength(0);
  });

  it('BC2: GET /book-categories/:id returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/book-categories/1' });
    expect(res.statusCode).toBe(404);
  });

  it('BC3: GET /book-categories/:id/books returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/book-categories/1/books' });
    expect(res.statusCode).toBe(404);
  });
});
