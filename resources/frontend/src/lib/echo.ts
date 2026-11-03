/**
 * Real-time bid gateway client.
 *
 * Connects to the NestJS BidGateway (socket.io, namespace /bids).
 * The API host is derived from VITE_API_BASE_URL at build time.
 *
 * Usage:
 *   import { subscribeLot, unsubscribeLot, onBidPlaced, onLotClosed } from './echo';
 *
 *   const cleanup = onBidPlaced(42, (event) => console.log(event));
 *   subscribeLot(42);
 *   // ... cleanup() when unmounting
 */

import { io, Socket } from 'socket.io-client';

const apiBase = import.meta.env.VITE_API_BASE_URL as string ?? '/api/v1';
// Strip /api/v1 path — socket.io connects to the host root, then to the /bids namespace.
const socketHost = apiBase.startsWith('http') ? new URL(apiBase).origin : '';

let socket: Socket | null = null;

function getSocket(): Socket {
  if (!socket) {
    socket = io(`${socketHost}/bids`, {
      transports: ['websocket'],
      autoConnect: false,
    });
  }
  return socket;
}

export function subscribeLot(lotId: number): void {
  const s = getSocket();
  if (!s.connected) s.connect();
  s.emit('subscribe-lot', { lotId });
}

export function unsubscribeLot(lotId: number): void {
  getSocket().emit('unsubscribe-lot', { lotId });
}

export function onBidPlaced(lotId: number, handler: (event: BidPlacedEvent) => void): () => void {
  const s = getSocket();
  const wrappedHandler = (event: BidPlacedEvent) => {
    if (event.lotId === lotId) handler(event);
  };
  s.on('bid:placed', wrappedHandler);
  return () => s.off('bid:placed', wrappedHandler);
}

export function onLotClosed(lotId: number, handler: (event: LotClosedEvent) => void): () => void {
  const s = getSocket();
  const wrappedHandler = (event: LotClosedEvent) => {
    if (event.lotId === lotId) handler(event);
  };
  s.on('lot:closed', wrappedHandler);
  return () => s.off('lot:closed', wrappedHandler);
}

export interface BidPlacedEvent {
  lotId: number;
  bidId: number;
  amount_cents: number;
  currency: string;
  bidder_id: number;
  placed_at: string;
}

export interface LotClosedEvent {
  lotId: number;
  winner_user_id: number | null;
  winning_bid_cents: number | null;
  currency: string;
}
