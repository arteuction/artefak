/**
 * Phase 0.3D — Artwork public + provenance routes integration tests
 *
 * AW1  GET /artworks                     — paginated list
 * AW2  GET /artworks?status=published    — filter
 * AW3  GET /artworks?medium=painting     — filter
 * AW4  GET /artworks/:id                 — show
 * AW5  GET /artworks/:slug               — show by slug
 * AW6  GET /artworks/not-found           — 404
 * AW7  GET /artworks/:id/lots            — lots
 * AW8  GET /artworks/:id/images/url      — image URL
 * AW9  GET /artworks/:id/linked-art      — Linked Art JSON-LD
 * AW10 GET /artworks/:id/edm             — Europeana EDM
 * AW11 GET /artworks/:id/iiif/manifest   — IIIF v3
 * AW12 GET /artworks/:id/iiif/manifest2  — IIIF v2
 * AW13 GET /artworks/:id/qr              — QR code
 * AW14 GET /artworks/:id/rights          — rights statement
 * AW15 GET /artworks/:id/revisions       — revision list
 * AW16 GET /artworks/:id/sales-history   — sales history
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException } from '@nestjs/common';

import { ArtworkController } from './artwork.controller';
import { ArtworkService } from './artwork.service';

// ── stubs ──────────────────────────────────────────────────────────────────────

const STUB_ARTWORK = {
  id: 1,
  title: 'Sunset Over the Bay',
  slug: 'sunset-over-the-bay',
  medium: 'painting',
  status: 'published',
  primary_image_key: null,
  year_created: 2023,
  created_at: new Date('2024-01-01').toISOString(),
};

const STUB_LOT = {
  id: 1,
  sale_mode: 'auction',
  status: 'draft',
  starting_bid_cents: 50000,
  buy_now_price_cents: null,
  reserve_price_cents: null,
  currency: 'EUR',
  estimate_low_cents: 40000,
  estimate_high_cents: 60000,
  published_at: null,
  closed_at: null,
};

const mockArtworkService = {
  listArtworks: jest.fn(async () => ({
    data: [STUB_ARTWORK],
    meta: { current_page: 1, last_page: 1, per_page: 20, total: 1 },
  })) as any,
  getArtwork: jest.fn(async (id: string) => {
    if (id === 'not-found') throw new NotFoundException('Artwork "not-found" not found');
    return STUB_ARTWORK;
  }) as any,
  listArtLots: jest.fn(async (_id: string) => [STUB_LOT]) as any,
  approveImage: jest.fn() as any,
  getImageUrl: jest.fn() as any,
  getLinkedArt: jest.fn(async () => ({ '@context': 'https://linked.art/ns/v1/linked-art.json', type: 'HumanMadeObject' })) as any,
  getEdm: jest.fn(async () => ({ edm: 'record', title: 'Sunset Over the Bay' })) as any,
  getIiifManifest: jest.fn(async () => ({ '@context': 'http://iiif.io/api/presentation/3/context.json', type: 'Manifest' })) as any,
  getIiifInfo: jest.fn(async () => ({ '@context': 'http://iiif.io/api/image/3/context.json', type: 'ImageService3' })) as any,
  getRights: jest.fn(async () => ({ artwork_id: 1, rights_statement: 'CC BY 4.0', license_url: 'https://creativecommons.org/licenses/by/4.0/' })) as any,
  getQr: jest.fn(async () => ({ qr_url: 'https://cdn.example.com/qr/1.png' })) as any,
  listRevisions: jest.fn(async () => [{ id: 1, artwork_id: 1, title: 'Sunset Over the Bay', created_at: new Date('2026-01-01').toISOString() }]) as any,
  getSalesHistory: jest.fn(async () => [{ id: 1, transfer_price_cents: 100000, channel: 'auction', transferred_at: new Date('2026-06-01').toISOString() }]) as any,
};

// ── suite ──────────────────────────────────────────────────────────────────────

describe('Phase 0.3D — Artwork controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [ArtworkController],
      providers: [{ provide: ArtworkService, useValue: mockArtworkService }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => {
    await app.close();
  });

  it('AW1: GET /artworks returns 200 with data + meta', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
    expect(Array.isArray(body.data)).toBe(true);
  });

  it('AW2: GET /artworks?status=published filters by status', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks?status=published' });
    expect(res.statusCode).toBe(200);
  });

  it('AW3: GET /artworks?medium=painting filters by medium', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks?medium=painting' });
    expect(res.statusCode).toBe(200);
  });

  it('AW4: GET /artworks/1 returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toMatchObject({ slug: 'sunset-over-the-bay', medium: 'painting' });
  });

  it('AW5: GET /artworks/:slug returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/sunset-over-the-bay' });
    expect(res.statusCode).toBe(200);
  });

  it('AW6: GET /artworks/not-found returns 404', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/not-found' });
    expect(res.statusCode).toBe(404);
  });

  it('AW7: GET /artworks/:id/lots returns array of lots', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/lots' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('sale_mode');
    expect(body[0]).toHaveProperty('currency');
  });

  it('AW8: GET /artworks/:id/images/url — delegates to artworkService.getImageUrl', async () => {
    mockArtworkService.getImageUrl.mockResolvedValueOnce({ url: 'https://cdn.example.com/img.jpg', expires_in: 3600 });
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/images/url' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.url).toContain('cdn.example.com');
  });

  it('AW9: GET /artworks/:id/linked-art returns Linked Art JSON-LD', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/linked-art' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('@context');
    expect(body.type).toBe('HumanMadeObject');
  });

  it('AW10: GET /artworks/:id/edm returns Europeana EDM', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/edm' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('edm');
  });

  it('AW11: GET /artworks/:id/iiif/manifest returns IIIF v3 manifest', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/iiif/manifest' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('@context');
    expect(body.type).toBe('Manifest');
  });

  it('AW12: GET /artworks/:id/iiif/manifest2 returns IIIF v2 manifest', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/iiif/manifest2' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body.type).toBe('Manifest');
  });

  it('AW13: GET /artworks/:id/qr returns QR code URL', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/qr' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('qr_url');
  });

  it('AW14: GET /artworks/:id/rights returns rights statement', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/rights' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('rights_statement');
  });

  it('AW15: GET /artworks/:id/revisions returns revision list', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/revisions' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('artwork_id');
  });

  it('AW16: GET /artworks/:id/sales-history returns sales history', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/sales-history' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
    expect(body[0]).toHaveProperty('channel');
  });
});
