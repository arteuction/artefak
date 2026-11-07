/**
 * Phase 0.3U — BidGateway unit tests
 *
 * BG1  emitBidPlaced — broadcasts bid:placed to correct lot room
 * BG2  emitLotClosed — broadcasts lot:closed to correct lot room
 * BG3  handleSubscribeLot — client joins room and receives subscribed ack
 * BG4  handleUnsubscribeLot — client leaves room and receives unsubscribed ack
 * BG5  emitBidPlaced — no-ops gracefully when server is undefined
 * BG6  handleSubscribeUser — room is always user:{JWT userId}, body params are ignored (cross-user structurally impossible)
 * BG7  handleSubscribeUser — emits TOKEN_EXPIRED when JWT has expired since connect time
 */

import { jest } from '@jest/globals';
import jwt from 'jsonwebtoken';
import { Test, TestingModule } from '@nestjs/testing';
import { BidGateway, BidPlacedEvent, LotClosedEvent } from './bid.gateway';

describe('Phase 0.3U — BidGateway', () => {
  let gateway: BidGateway;

  const mockTo = jest.fn() as any;
  const mockEmit = jest.fn() as any;
  const mockServer = {
    to: mockTo,
  };

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [BidGateway],
    }).compile();
    gateway = module.get(BidGateway);
    mockTo.mockReturnValue({ emit: mockEmit });
    (gateway as any).server = mockServer;
  });

  beforeEach(() => {
    jest.clearAllMocks();
    mockTo.mockReturnValue({ emit: mockEmit });
  });

  it('BG1: emitBidPlaced — sends bid:placed to lot room', () => {
    const event: BidPlacedEvent = {
      lotId: 42,
      bidId: 7,
      amount_cents: 150000,
      currency: 'EUR',
      bidder_id: 99,
      placed_at: new Date().toISOString(),
    };

    gateway.emitBidPlaced(event);

    expect(mockServer.to).toHaveBeenCalledWith('lot:42');
    // bidder_id is stripped before broadcast — public shape only
    const { bidder_id: _stripped, ...publicEvent } = event;
    expect(mockEmit).toHaveBeenCalledWith('bid:placed', publicEvent);
  });

  it('BG2: emitLotClosed — sends lot:closed to lot room', () => {
    const event: LotClosedEvent = {
      lotId: 42,
      winner_user_id: 99,
      winning_bid_cents: 150000,
      currency: 'EUR',
    };

    gateway.emitLotClosed(event);

    expect(mockServer.to).toHaveBeenCalledWith('lot:42');
    // winner_user_id stripped from public broadcast
    const { winner_user_id: _w, ...publicEvent } = event;
    expect(mockEmit).toHaveBeenCalledWith('lot:closed', publicEvent);
  });

  it('BG3: handleSubscribeLot — client joins room and receives subscribed ack', () => {
    const mockJoin = jest.fn() as any;
    const mockClientEmit = jest.fn() as any;
    const mockClient = { id: 'socket-1', join: mockJoin, emit: mockClientEmit } as any;

    gateway.handleSubscribeLot(mockClient, { lotId: 42 });

    expect(mockJoin).toHaveBeenCalledWith('lot:42');
    expect(mockClientEmit).toHaveBeenCalledWith('subscribed', { lotId: 42 });
  });

  it('BG4: handleUnsubscribeLot — client leaves room and receives unsubscribed ack', () => {
    const mockLeave = jest.fn() as any;
    const mockClientEmit = jest.fn() as any;
    const mockClient = { id: 'socket-1', leave: mockLeave, emit: mockClientEmit } as any;

    gateway.handleUnsubscribeLot(mockClient, { lotId: 42 });

    expect(mockLeave).toHaveBeenCalledWith('lot:42');
    expect(mockClientEmit).toHaveBeenCalledWith('unsubscribed', { lotId: 42 });
  });

  it('BG5: emitBidPlaced — no-ops when server is undefined', () => {
    (gateway as any).server = undefined;
    const event: BidPlacedEvent = {
      lotId: 1,
      bidId: 1,
      amount_cents: 1000,
      currency: 'EUR',
      bidder_id: 1,
      placed_at: new Date().toISOString(),
    };
    expect(() => gateway.emitBidPlaced(event)).not.toThrow();
    (gateway as any).server = mockServer;
  });

  it('BG6: handleSubscribeUser — room is user:{JWT userId}, ignores any client-supplied userId', () => {
    // This test confirms the structural guarantee: the room is derived from
    // client.data.userId (set from JWT at connect time), not from any message body.
    // handleSubscribeUser takes no @MessageBody — cross-user subscription is impossible by design.
    const secret = 'test-secret';
    process.env.JWT_SECRET = secret;
    const token = jwt.sign({ sub: 10 }, secret, { expiresIn: '1h' });

    const mockJoin = jest.fn() as any;
    const mockClientEmit = jest.fn() as any;
    const mockClient = {
      id: 'socket-bg6',
      join: mockJoin,
      emit: mockClientEmit,
      data: { authenticated: true, userId: 10 },
      handshake: { auth: { token }, headers: {} },
    } as any;

    gateway.handleSubscribeUser(mockClient);

    // Must join user:10 (from JWT), not any other room
    expect(mockJoin).toHaveBeenCalledWith('user:10');
    expect(mockClientEmit).toHaveBeenCalledWith('subscribed-user', { userId: 10 });
    delete process.env.JWT_SECRET;
  });

  it('BG7: handleSubscribeUser — emits TOKEN_EXPIRED when JWT has expired since connect time', () => {
    const secret = 'test-secret';
    process.env.JWT_SECRET = secret;
    // Construct a token whose exp is in the past: iat = 2h ago, expiresIn = 1s → exp = iat+1 (past)
    const iatPast = Math.floor(Date.now() / 1000) - 7200;
    // Override exp to be in the past: iat=2h ago, exp=iat+1 (expired)
    const alreadyExpiredTokenActual = jwt.sign(
      { sub: 5, iat: iatPast, exp: iatPast + 1 },
      secret,
    );

    const mockJoin = jest.fn() as any;
    const mockClientEmit = jest.fn() as any;
    const mockClient = {
      id: 'socket-bg7',
      join: mockJoin,
      emit: mockClientEmit,
      // Simulates a connection that was authenticated at connect time but token has since expired
      data: { authenticated: true, userId: 5 },
      handshake: { auth: { token: alreadyExpiredTokenActual }, headers: {} },
    } as any;

    gateway.handleSubscribeUser(mockClient);

    // Must NOT join any private room
    expect(mockJoin).not.toHaveBeenCalled();
    // Must emit TOKEN_EXPIRED, not subscribed-user
    expect(mockClientEmit).toHaveBeenCalledWith('error', expect.objectContaining({ code: 'TOKEN_EXPIRED' }));
    expect(mockClientEmit).not.toHaveBeenCalledWith('subscribed-user', expect.anything());
    // client.data.authenticated must be reset to false
    expect(mockClient.data.authenticated).toBe(false);
    delete process.env.JWT_SECRET;
  });
});
