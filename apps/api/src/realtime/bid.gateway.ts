import {
  WebSocketGateway,
  WebSocketServer,
  SubscribeMessage,
  MessageBody,
  ConnectedSocket,
  OnGatewayInit,
  OnGatewayConnection,
  OnGatewayDisconnect,
} from '@nestjs/websockets';
import { Logger } from '@nestjs/common';
import jwt from 'jsonwebtoken';
import type { Server, Socket } from 'socket.io';

export interface BidPlacedEvent {
  lotId: number;
  bidId: number;
  amount_cents: number;
  currency: string;
  /** Internal only — stripped before public broadcast */
  bidder_id: number;
  placed_at: string;
}

/** Publicly broadcast shape — bidder_id deliberately omitted */
interface BidPlacedPublic {
  lotId: number;
  bidId: number;
  amount_cents: number;
  currency: string;
  placed_at: string;
}

export interface LotClosedEvent {
  lotId: number;
  winner_user_id: number | null;
  winning_bid_cents: number | null;
  currency: string;
}

/**
 * Real-time bid gateway — emits events to lot-specific rooms.
 *
 * Clients subscribe to a lot room: socket.emit('subscribe-lot', { lotId: 42 })
 * They then receive:
 *   - 'bid:placed'  on every new accepted bid
 *   - 'lot:closed'  when the lot is sold or unsold
 */
@WebSocketGateway({
  cors: {
    origin: process.env.CORS_ORIGINS?.split(',').map(o => o.trim()) ?? ['http://localhost:5173', 'http://localhost:5174'],
    credentials: true,
  },
  namespace: 'bids',
})
export class BidGateway implements OnGatewayInit, OnGatewayConnection, OnGatewayDisconnect {
  @WebSocketServer() server!: Server;

  private readonly logger = new Logger(BidGateway.name);

  afterInit() {
    this.logger.log('BidGateway initialized');
  }

  handleConnection(client: Socket) {
    const jwtSecret = process.env.JWT_SECRET;
    if (jwtSecret) {
      const token =
        (client.handshake.auth as Record<string, string>)?.token ??
        (client.handshake.headers.authorization ?? '').replace(/^Bearer\s+/i, '');
      if (!token) {
        this.logger.warn(`WS: unauthenticated connection from ${client.id} — disconnecting`);
        client.disconnect(true);
        return;
      }
      try {
        jwt.verify(token, jwtSecret);
      } catch {
        this.logger.warn(`WS: invalid JWT from ${client.id} — disconnecting`);
        client.disconnect(true);
        return;
      }
    }
    this.logger.debug(`Client connected: ${client.id}`);
  }

  handleDisconnect(client: Socket) {
    this.logger.debug(`Client disconnected: ${client.id}`);
  }

  @SubscribeMessage('subscribe-lot')
  handleSubscribeLot(@ConnectedSocket() client: Socket, @MessageBody() data: { lotId: number }) {
    const room = `lot:${data.lotId}`;
    void client.join(room);
    client.emit('subscribed', { lotId: data.lotId });
    this.logger.debug(`Client ${client.id} subscribed to ${room}`);
  }

  @SubscribeMessage('unsubscribe-lot')
  handleUnsubscribeLot(@ConnectedSocket() client: Socket, @MessageBody() data: { lotId: number }) {
    const room = `lot:${data.lotId}`;
    void client.leave(room);
    client.emit('unsubscribed', { lotId: data.lotId });
  }

  /** Called by AuctionService/BidService when a bid is accepted. */
  emitBidPlaced(event: BidPlacedEvent): void {
    // Strip bidder_id before broadcast — bid amounts are public, identities are not
    const { bidder_id: _stripped, ...publicEvent }: { bidder_id: number } & BidPlacedPublic = event;
    this.server?.to(`lot:${event.lotId}`).emit('bid:placed', publicEvent);
  }

  /** Called by SchedulerService.closeExpiredLots when a lot closes. */
  emitLotClosed(event: LotClosedEvent): void {
    this.server?.to(`lot:${event.lotId}`).emit('lot:closed', event);
  }
}
