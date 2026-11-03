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

export interface LotClosedEvent {
  lotId: number;
  winner_user_id: number | null;
  winning_bid_cents: number | null;
  currency: string;
}

/** Publicly broadcast shape — bidder_id deliberately omitted */
interface BidPlacedPublic {
  lotId: number;
  bidId: number;
  amount_cents: number;
  currency: string;
  placed_at: string;
}

/**
 * Real-time bid gateway — emits public auction events to lot-specific rooms.
 *
 * Authentication strategy:
 *   - Public connections (no JWT): allowed for public lot event subscriptions.
 *     Bid amounts are public information. Unauthenticated users may watch auctions.
 *   - Authenticated connections (valid JWT): allowed everywhere.
 *     Private channels (e.g. user-specific notifications) require authentication.
 *
 * Clients subscribe to a lot room:  socket.emit('subscribe-lot', { lotId: 42 })
 * They then receive:
 *   - 'bid:placed'  on every new accepted bid (amount only, no bidder identity)
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
    // Attempt JWT verification — sets client.data.userId when valid.
    // Unauthenticated connections are allowed for public lot subscriptions.
    const jwtSecret = process.env.JWT_SECRET;
    if (jwtSecret) {
      const token =
        (client.handshake.auth as Record<string, string>)?.token ??
        (client.handshake.headers.authorization ?? '').replace(/^Bearer\s+/i, '');

      if (token) {
        try {
          const payload = jwt.verify(token, jwtSecret) as { sub?: number | string };
          client.data.userId = payload.sub ? Number(payload.sub) : null;
          client.data.authenticated = true;
        } catch {
          // Invalid/expired token — treat as anonymous rather than disconnecting,
          // so public viewers with a stale token still see public bid updates.
          this.logger.debug(`WS: invalid JWT from ${client.id} — treating as anonymous`);
          client.data.authenticated = false;
        }
      } else {
        client.data.authenticated = false;
      }
    } else {
      // No JWT_SECRET configured (development) — allow all
      client.data.authenticated = true;
    }

    this.logger.debug(`Client connected: ${client.id} (authenticated=${client.data.authenticated as boolean})`);
  }

  handleDisconnect(client: Socket) {
    this.logger.debug(`Client disconnected: ${client.id}`);
  }

  @SubscribeMessage('subscribe-lot')
  handleSubscribeLot(@ConnectedSocket() client: Socket, @MessageBody() data: { lotId: number }) {
    // Public lot rooms — open to all connected clients (authenticated or not)
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

  /** Subscribe to private user-specific events. Requires authentication. */
  @SubscribeMessage('subscribe-user')
  handleSubscribeUser(@ConnectedSocket() client: Socket) {
    if (!client.data.authenticated || !client.data.userId) {
      client.emit('error', { code: 'UNAUTHORIZED', message: 'Authentication required for private subscriptions' });
      return;
    }
    const room = `user:${client.data.userId as number}`;
    void client.join(room);
    client.emit('subscribed-user', { userId: client.data.userId });
    this.logger.debug(`Client ${client.id} subscribed to private ${room}`);
  }

  /** Called by BidConcurrencyService when a bid is accepted. */
  emitBidPlaced(event: BidPlacedEvent): void {
    // Strip bidder_id before broadcast — bid amounts are public, identities are not
    const { bidder_id: _stripped, ...publicEvent }: { bidder_id: number } & BidPlacedPublic = event;
    this.server?.to(`lot:${event.lotId}`).emit('bid:placed', publicEvent);
  }

  /** Called by SchedulerService.closeExpiredLots when a lot closes. */
  emitLotClosed(event: LotClosedEvent): void {
    // winner_user_id is included only in the private user room notification,
    // not in the public lot room broadcast
    const { winner_user_id: _winnerId, ...publicEvent } = event;
    this.server?.to(`lot:${event.lotId}`).emit('lot:closed', publicEvent);

    // Notify winner privately
    if (event.winner_user_id) {
      this.server?.to(`user:${event.winner_user_id}`).emit('lot:won', event);
    }
  }
}
