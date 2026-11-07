/**
 * Phase 0.3R — BookCategory route tests
 *
 * BC1  GET /book-categories       — 200 with pagination data
 * BC2  GET /book-categories/:id   — 200 returns category
 * BC3  GET /book-categories/:id/books — 200 returns paginated books
 * BC4  GET /book-categories/:id   — 404 when not found
 * BC5  GET /book-categories/:id/books — 404 when category not found
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';
import { BookCategoryController } from './book-category.controller';
import { BookCategoryService } from './book-category.service';

const STUB_CATEGORY = { id: 1, name: 'Photography', slug: 'photography', created_at: new Date().toISOString() };
const STUB_BOOK = { id: 10, title: 'The Eye of the Lens', slug: 'eye-lens', category_id: 1, price_cents: 2500, currency: 'EUR', created_at: new Date().toISOString() };

const mockService = {
  listCategories: async () => ({ data: [STUB_CATEGORY], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } }),
  getCategory: async (id: string) => {
    if (id === '999') throw new NotFoundException(`Category ${id} not found`);
    return STUB_CATEGORY;
  },
  getCategoryBooks: async (id: string) => {
    if (id === '999') throw new NotFoundException(`Category ${id} not found`);
    return { data: [STUB_BOOK], meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 } };
  },
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

  it('BC1: GET /book-categories returns 200 with paginated list', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/book-categories' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body.data)).toBe(true);
    expect(body.data[0]).toHaveProperty('slug', 'photography');
    expect(body.meta.total).toBe(1);
  });

  it('BC2: GET /book-categories/:id returns 200 with category', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/book-categories/photography' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('name', 'Photography');
  });

  it('BC3: GET /book-categories/:id/books returns 200 with paginated books', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/book-categories/photography/books' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.data[0]).toHaveProperty('title', 'The Eye of the Lens');
  });

  it('BC4: GET /book-categories/999 returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/book-categories/999' });
    expect(res.statusCode).toBe(404);
  });

  it('BC5: GET /book-categories/999/books returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/book-categories/999/books' });
    expect(res.statusCode).toBe(404);
  });
});
