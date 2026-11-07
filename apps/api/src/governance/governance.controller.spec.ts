/**
 * Phase 0.3R — Governance route tests
 *
 * GV1  GET /governance/policies — 200 public list
 * GV2  POST /governance/policies/:id/consent — 200 (authenticated)
 * GV3  DELETE /governance/policies/:id/consent — 200 (authenticated)
 * GV4  GET /governance/policies — 200 without auth (public)
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { JwtModule } from '@nestjs/jwt';
import { PassportModule } from '@nestjs/passport';
import { PassportStrategy } from '@nestjs/passport';
import { ExtractJwt, Strategy } from 'passport-jwt';
import { Injectable } from '@nestjs/common';
import { GovernanceController } from './governance.controller';
import { GovernanceService } from './governance.service';
import type { AuthenticatedUser, JwtPayload } from '../auth/auth.types';

const JWT_SECRET = 'test-governance-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(Strategy, 'jwt') {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), ignoreExpiration: false, secretOrKey: JWT_SECRET });
  }
  async validate(payload: JwtPayload): Promise<AuthenticatedUser> {
    return { id: payload.sub, email: payload.email, role: payload.role as AuthenticatedUser['role'], emailVerifiedAt: null };
  }
}

const STUB_POLICY = { id: 1, type: 'privacy', version: '1.0', effective_from: new Date('2026-01-01').toISOString(), content_url: null, created_at: new Date('2026-01-01').toISOString() };

const mockService = {
  listPolicies: async () => [STUB_POLICY],
  giveConsent: async () => ({ id: 1, governance_policy_id: 1, consented_at: new Date().toISOString() }),
  withdrawConsent: async () => ({ id: 1, governance_policy_id: 1, withdrawn_at: new Date().toISOString() }),
};

import jwt from 'jsonwebtoken';
const { sign } = jwt;
function makeToken(role = 'user') {
  return sign({ sub: 1, email: 'user@example.com', role }, JWT_SECRET, { expiresIn: '1h' });
}

describe('Phase 0.3R — Governance controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: JWT_SECRET })],
      controllers: [GovernanceController],
      providers: [{ provide: GovernanceService, useValue: mockService }, TestJwtStrategy],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('GV1: GET /governance/policies returns 200 (public)', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/governance/policies' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(Array.isArray(body)).toBe(true);
  });

  it('GV2: POST /governance/policies/:id/consent returns 200', async () => {
    const res = await app.inject({
      method: 'POST', url: '/api/v1/governance/policies/1/consent',
      headers: { authorization: `Bearer ${makeToken()}`, 'content-type': 'application/json' },
      payload: JSON.stringify({}),
    });
    expect(res.statusCode).toBe(200);
  });

  it('GV3: DELETE /governance/policies/:id/consent returns 200', async () => {
    const res = await app.inject({
      method: 'DELETE', url: '/api/v1/governance/policies/1/consent',
      headers: { authorization: `Bearer ${makeToken()}` },
    });
    expect(res.statusCode).toBe(200);
  });

  it('GV4: GET /governance/policies returns 401 without auth (protected mutations, not list)', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/governance/policies' });
    expect(res.statusCode).toBe(200);
  });
});
