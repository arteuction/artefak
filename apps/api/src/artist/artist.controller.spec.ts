/**
 * Phase 0.3H — Artist profile public routes integration tests
 *
 * Routes covered:
 *   GET /api/v1/artists               — paginated approved artist list
 *   GET /api/v1/artists/:id           — show by ID or slug (approved only)
 *   GET /api/v1/artists/not-found     — 404 path
 *   GET /api/v1/artists/:id/artworks  — artist's artworks (paginated)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';

import { ArtistController } from './artist.controller';
import { ArtistService } from './artist.service';

const STUB_ARTIST = {
  id: 1,
  display_name: 'Maria Kowalski',
  slug: 'maria-kowalski',
  bio: 'Contemporary painter.',
  website: null,
  instagram_handle: 'mariakowalski',
  status: 'approved',
  created_at: new Date('2024-06-01').toISOString(),
};

const STUB_ARTWORKS_RESPONSE = {
  data: [{ id: 1, title: 'Blue Horizon', slug: 'blue-horizon', medium: 'painting', status: 'published' }],
  meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
};

const mockArtistService = {
  listArtists: async () => ({
    data: [STUB_ARTIST],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  }),
  getArtist: async (id: string) => {
    if (id === 'not-found') throw new NotFoundException('Artist "not-found" not found');
    return STUB_ARTIST;
  },
  getArtistArtworks: async () => STUB_ARTWORKS_RESPONSE,
};

describe('Phase 0.3H — Artist controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ArtistController],
      providers: [{ provide: ArtistService, useValue: mockArtistService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => {
    await app.close();
  });

  it('AR1: GET /artists returns 200 with data + meta', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artists' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(Array.isArray(body.data)).toBe(true);
  });

  it('AR2: GET /artists/1 returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artists/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ slug: 'maria-kowalski', status: 'approved' });
  });

  it('AR3: GET /artists/:slug returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artists/maria-kowalski' });
    expect(res.statusCode).toBe(200);
  });

  it('AR4: GET /artists/not-found returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artists/not-found' });
    expect(res.statusCode).toBe(404);
  });

  it('AR5: GET /artists/:id/artworks returns paginated artworks', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artists/1/artworks' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(body.data[0]).toHaveProperty('slug', 'blue-horizon');
  });
});
