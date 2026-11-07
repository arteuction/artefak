/**
 * Phase 0.3U â€” BidController unit tests (no DB)
 *
 * BID1  POST /auctions/1/items/1/bids (auth, valid amount)  â€” 201 with bid_id
 * BID2  POST missing amount_cents                           â€” 400
 * BID3  POST amount_cents = 0                               â€” 400
 * BID4  POST service throws BidRejected                     â€” 422
 * BID5  POST without token                                  â€” 401
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { INestApplication } from '@nestjs/common';
import { PassportModule } from '@nestjs/passport';
import { JwtModule } from '@nestjs/jwt';
import { Strategy as JwtStrategy, ExtractJwt } from 'passport-jwt';
import { PassportStrategy } from '@nestjs/passport';
import { Injectable } from '@nestjs/common';
import { BidController } from './bid.controller';
import { BidConcurrencyService, BidRejected } from './bid-concurrency.service';
import jwt from 'jsonwebtoken';

const { sign } = jwt;

const TEST_SECRET = 'bid-ctrl-test-secret';

@Injectable()
class TestJwtStrategy extends PassportStrategy(JwtStrategy) {
  constructor() {
    super({ jwtFromRequest: ExtractJwt.fromAuthHeaderAsBearerToken(), secretOrKey: TEST_SECRET });
  }
  validate(payload: { sub: number; role: string }) {
    return { id: payload.sub, role: payload.role };
  }
}

const mockBidService = {
  placeBid: jest.fn() as any,
};

describe('Phase 0.3U â€” BidController', () => {
  let app: INestApplication;
  let buyerToken: string;

  beforeAll(async () => {
    buyerToken = sign({ sub: 42, role: 'buyer' }, TEST_SECRET, { expiresIn: '1h' });

    const module: TestingModule = await Test.createTestingModule({
      imports: [PassportModule, JwtModule.register({ secret: TEST_SECRET })],
      controllers: [BidController],
      providers: [
        TestJwtStrategy,
        { provide: BidConcurrencyService, useValue: mockBidService },
      ],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await (app as NestFastifyApplication).getHttpAdapter().getInstance().ready();
  });

  beforeEach(() => { mockBidService.placeBid.mockReset(); });

  afterAll(async () => { await app.close(); });

  it('BID1: POST /auctions/1/items/1/bids returns 201 with bid_id', async () => {
    mockBidService.placeBid.mockResolvedValueOnce({ bidId: BigInt(99), amountCents: BigInt(5000), status: 'accepted' });

    const res = await (app as NestFastifyApplication).inject({
      method: 'POST',
      url: '/api/v1/auctions/1/items/1/bids',
      headers: { authorization: `Bearer ${buyerToken}` },
      payload: { amount_cents: 5000 },
    });
    expect(res.statusCode).toBe(201);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('bid_id');
    expect(body).toHaveProperty('amount_cents');
    expect(body.status).toBe('accepted');
  });

  it('BID2: POST missing amount_cents returns 400', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'POST',
      url: '/api/v1/auctions/1/items/1/bids',
      headers: { authorization: `Bearer ${buyerToken}` },
      payload: {},
    });
    expect(res.statusCode).toBe(400);
  });

  it('BID3: POST amount_cents = 0 returns 400', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'POST',
      url: '/api/v1/auctions/1/items/1/bids',
      headers: { authorization: `Bearer ${buyerToken}` },
      payload: { amount_cents: 0 },
    });
    expect(res.statusCode).toBe(400);
  });

  it('BID4: POST when service throws BidRejected returns 422', async () => {
    mockBidService.placeBid.mockRejectedValueOnce(new BidRejected('Outbid'));

    const res = await (app as NestFastifyApplication).inject({
      method: 'POST',
      url: '/api/v1/auctions/1/items/1/bids',
      headers: { authorization: `Bearer ${buyerToken}` },
      payload: { amount_cents: 100 },
    });
    expect(res.statusCode).toBe(422);
  });

  it('BID5: POST without token returns 401', async () => {
    const res = await (app as NestFastifyApplication).inject({
      method: 'POST',
      url: '/api/v1/auctions/1/items/1/bids',
      payload: { amount_cents: 5000 },
    });
    expect(res.statusCode).toBe(401);
  });
});
