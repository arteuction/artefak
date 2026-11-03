/**
 * Phase 0.3U — BidGateway unit tests
 *
 * BG1  emitBidPlaced — broadcasts bid:placed to correct lot room
 * BG2  emitLotClosed — broadcasts lot:closed to correct lot room
 * BG3  handleSubscribeLot — client joins room and receives subscribed ack
 * BG4  handleUnsubscribeLot — client leaves room and receives unsubscribed ack
 * BG5  emitBidPlaced — no-ops gracefully when server is undefined
 */

import { jest } from '@jest/globals';
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
    expect(mockEmit).toHaveBeenCalledWith('bid:placed', event);
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
    expect(mockEmit).toHaveBeenCalledWith('lot:closed', event);
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
});
