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
 * AW9  GET   /artworks/:id/linked-art               — Linked Art JSON-LD
 * AW10 GET   /artworks/:id/edm                     — Europeana EDM
 * AW11 GET   /artworks/:id/iiif/manifest            — IIIF v3
 * AW12 GET   /artworks/:id/iiif/manifest2           — IIIF v2
 * AW13 GET   /artworks/:id/qr                      — QR code
 * AW14 GET   /artworks/:id/rights                  — rights statement
 * AW15 GET   /artworks/:id/revisions               — revision list
 * AW16 GET   /artworks/:id/sales-history           — sales history
 * AW17 POST  /artworks (auth)                      — create artwork
 * AW18 PATCH /artworks/:id (auth)                  — update artwork
 * AW19 POST  /artworks/:id/submit (auth)           — submit for review
 * AW20 GET   /artworks/:id/evidence                — list evidence
 * AW21 POST  /artworks/:id/evidence (auth)         — submit evidence
 * AW22 GET   /artworks/:id/sdg-claims              — list SDG claims
 * AW23 POST  /artworks/:id/sdg-claims (auth)       — create SDG claim
 * AW24 GET   /artworks/:id/iiif/info.json          — IIIF image info
 * AW25 PATCH /artworks/:id/rights (auth)           — update rights
 * AW26 POST  /artworks/:id/revisions (auth)        — create revision
 * AW27 POST  /artworks/:id/revisions/:rev/activate — activate revision
 * AW28 GET   /artworks/:id/sdg-claims/:claim       — show SDG claim
 * AW29 POST  /artworks/:id/sdg-claims/:claim/review — review SDG claim
 * AW30 POST  /artworks/:id/images/presign (auth)   — presign upload
 * AW31 POST  /artworks/:id/images/confirm (auth)   — confirm upload
 * AW32 POST  /artworks/:id/images/derivatives      — generate derivatives
 * AW33 POST  /artworks/:id/images/approve (auth)   — approve image
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { NotFoundException, Injectable } from '@nestjs/common';
import { PassportModule } from '@nestjs/passport';
import { JwtModule } from '@nestjs/jwt';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import jwt from 'jsonwebtoken';

import { ArtworkController } from './artwork.controller';
import { ArtworkService } from './artwork.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-artwork-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const { sign } = jwt;
function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'artist@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

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
  createArtwork: jest.fn(async (_uid: number, dto: object) => ({ id: 2, ...dto, status: 'draft', created_at: new Date().toISOString() })) as any,
  updateArtwork: jest.fn(async (id: string, _uid: number, _role: string, dto: object) => ({ id: 1, slug: id, ...dto })) as any,
  submitArtwork: jest.fn(async () => ({ id: 1, status: 'pending_review' })) as any,
  listEvidence: jest.fn(async () => ({ data: [], meta: { total: 0 } })) as any,
  createEvidence: jest.fn(async () => ({ id: 1, type: 'certificate', status: 'pending' })) as any,
  listSdgClaims: jest.fn(async () => ({ data: [], meta: { total: 0 } })) as any,
  createSdgClaim: jest.fn(async () => ({ id: 1, sdg_number: 4, rationale: 'Education', status: 'pending' })) as any,
  updateRights: jest.fn(async (_id: string, _uid: number, _role: string, dto: object) => ({ artwork_id: 1, ...dto })) as any,
  createRevision: jest.fn(async () => ({ id: 5, artwork_id: 1, title: 'Revision', status: 'draft' })) as any,
  activateRevision: jest.fn(async () => ({ id: 5, artwork_id: 1, status: 'active' })) as any,
  getSdgClaim: jest.fn(async () => ({ id: 1, sdg_number: 4, rationale: 'Education', status: 'pending' })) as any,
  reviewSdgClaim: jest.fn(async () => ({ id: 1, sdg_number: 4, status: 'approved' })) as any,
  presignImage: jest.fn(async () => ({ upload_url: 'https://s3.example.com/presign', key: 'artworks/1/img.jpg', expires_in: 3600 })) as any,
  confirmImage: jest.fn(async () => ({ id: 1, key: 'artworks/1/img.jpg', status: 'pending_approval' })) as any,
  generateDerivatives: jest.fn(async () => ({ status: 'queued' })) as any,
};

// ── suite ──────────────────────────────────────────────────────────────────────

describe('Phase 0.3D — Artwork controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [ArtworkController],
      providers: [{ provide: ArtworkService, useValue: mockArtworkService }, TestJwtStrategy],
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

  it('AW17: POST /artworks (auth) creates artwork 201', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ title: 'New Art', slug: 'new-art', medium: 'oil' }) });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'draft');
  });

  it('AW18: PATCH /artworks/:id (auth) updates artwork 200', async () => {
    const res = await app.inject({ method: 'PATCH', url: '/api/v1/artworks/1', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ description: 'Updated' }) });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('description', 'Updated');
  });

  it('AW19: POST /artworks/:id/submit (auth) returns 200 pending_review', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks/1/submit', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({}) });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'pending_review');
  });

  it('AW20: GET /artworks/:id/evidence returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/evidence' });
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });

  it('AW21: POST /artworks/:id/evidence (auth) returns 201', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks/1/evidence', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ type: 'certificate' }) });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('type', 'certificate');
  });

  it('AW22: GET /artworks/:id/sdg-claims returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/sdg-claims' });
    expect(res.statusCode).toBe(200);
    expect(JSON.parse(res.payload)).toHaveProperty('data');
  });

  it('AW23: POST /artworks/:id/sdg-claims (auth) returns 201', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks/1/sdg-claims', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ sdg_number: 4, rationale: 'Education' }) });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('sdg_number', 4);
  });

  it('AW24: GET /artworks/:id/iiif/info.json returns IIIF image info', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/iiif/info.json' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('@context');
    expect(body.type).toBe('ImageService3');
  });

  it('AW25: PATCH /artworks/:id/rights (auth) returns 200', async () => {
    const res = await app.inject({ method: 'PATCH', url: '/api/v1/artworks/1/rights', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ rights_statement: 'CC BY 4.0' }) });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('rights_statement', 'CC BY 4.0');
  });

  it('AW26: POST /artworks/:id/revisions (auth) returns 201', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks/1/revisions', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ title: 'Revision' }) });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('artwork_id', 1);
  });

  it('AW27: POST /artworks/:id/revisions/:rev/activate (auth) returns 200', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks/1/revisions/5/activate', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({}) });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'active');
  });

  it('AW28: GET /artworks/:id/sdg-claims/:claim returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/artworks/1/sdg-claims/1' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('sdg_number');
  });

  it('AW29: POST /artworks/:id/sdg-claims/:claim/review (auth) returns 200', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks/1/sdg-claims/1/review', headers: { authorization: `Bearer ${makeToken('admin')}`, 'content-type': 'application/json' }, payload: JSON.stringify({ approved: true }) });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'approved');
  });

  it('AW30: POST /artworks/:id/images/presign (auth) returns 200 with upload_url', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks/1/images/presign', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ filename: 'img.jpg', content_type: 'image/jpeg' }) });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('upload_url');
  });

  it('AW31: POST /artworks/:id/images/confirm (auth) returns 200', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks/1/images/confirm', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({ key: 'artworks/1/img.jpg' }) });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'pending_approval');
  });

  it('AW32: POST /artworks/:id/images/derivatives (auth) returns 200', async () => {
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks/1/images/derivatives', headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' }, payload: JSON.stringify({}) });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'queued');
  });

  it('AW33: POST /artworks/:id/images/approve (auth) returns 200', async () => {
    mockArtworkService.approveImage.mockResolvedValueOnce({ id: 1, status: 'approved', key: 'artworks/1/approved.jpg' });
    const res = await app.inject({ method: 'POST', url: '/api/v1/artworks/1/images/approve', headers: { authorization: `Bearer ${makeToken('admin')}`, 'content-type': 'application/json' }, payload: JSON.stringify({ approved_key: 'artworks/1/approved.jpg' }) });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('status', 'approved');
  });
});
