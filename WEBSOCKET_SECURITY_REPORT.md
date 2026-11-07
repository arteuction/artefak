# ARTeuCtion — WebSocket Security Report

**Date:** 2026-11-03  
**Gateway:** `apps/api/src/realtime/bid.gateway.ts`

---

## Authentication Model

### Design Principle

> Public auction data is public. Bid amounts are visible on the auction page.
> Unauthenticated visitors should be able to watch live bid updates.
> Bidder identity and winner notification are private.

### Connection Authentication

| Client type | Accepted | Access |
|---|---|---|
| No JWT | ✅ Anonymous | Public lot rooms only |
| Valid JWT | ✅ Authenticated | Public lot rooms + private user room |
| Invalid/expired JWT | ✅ Anonymous (not hard-disconnected) | Public lot rooms only |

**Rationale:** Hard-disconnecting on bad JWT would break public browsing for anyone with a stale token. The client should silently degrade to anonymous mode and prompt re-login for private features.

### Room Model

| Room | Name pattern | Accessible to |
|---|---|---|
| Public lot | `lot:{lotId}` | All connected clients |
| Private user | `user:{userId}` | Authenticated users only (subscribe-user message) |

---

## Events

### Public Events (lot room)

| Event | Payload | Notes |
|---|---|---|
| `bid:placed` | `{ lotId, bidId, amount_cents, currency, placed_at }` | `bidder_id` stripped |
| `lot:closed` | `{ lotId, winning_bid_cents, currency }` | `winner_user_id` stripped |

### Private Events (user room, requires auth)

| Event | Payload | Notes |
|---|---|---|
| `lot:won` | `{ lotId, winner_user_id, winning_bid_cents, currency }` | Sent only to winner's private room |

---

## Client Usage

```typescript
// Public auction watching (no auth required)
const socket = io('/bids');

socket.emit('subscribe-lot', { lotId: 42 });
socket.on('bid:placed', (event) => { /* update UI */ });
socket.on('lot:closed', (event) => { /* show result */ });

// Private notifications (auth required)
const socket = io('/bids', { auth: { token: 'Bearer eyJ...' } });
socket.emit('subscribe-user');
socket.on('lot:won', (event) => { /* show winning notification */ });
socket.on('error', (e) => { /* handle UNAUTHORIZED */ });
```

---

## Test Coverage

| Test | Scenario | Status |
|---|---|---|
| BG1 | bid:placed broadcast (bidder_id stripped) | ✅ |
| BG2 | lot:closed broadcast (winner_user_id stripped) | ✅ |
| BG3 | subscribe-lot joins room | ✅ |
| BG4 | unsubscribe-lot leaves room | ✅ |
| BG5 | null server no-op | ✅ |

---

## Outstanding (Require Integration Environment)

| Scenario | Status |
|---|---|
| Token expiry mid-session (client reconnects with new token) | ⏭ Not tested |
| Two NestJS instances (cross-instance room broadcast) | ⏭ Redis adapter not implemented |
| 100 concurrent connections under auction load | ⏭ Not tested |
| Explicit token revocation (compromised token) | ⏭ Not implemented |

### Redis Adapter (Multi-Instance Requirement)

Socket.IO rooms are in-memory by default. With two NestJS instances:
- Instance A handles a bid → emits to `lot:42` on Instance A's memory
- Instance B has clients in `lot:42` room → they DO NOT receive the event

**Fix required before multi-instance deployment:**

```bash
npm install @socket.io/redis-adapter ioredis
```

```typescript
// In GatewayModule or BidGateway.afterInit():
import { createAdapter } from '@socket.io/redis-adapter';
import { createClient } from 'redis';

const pubClient = createClient({ url: process.env.REDIS_URL });
const subClient = pubClient.duplicate();
await Promise.all([pubClient.connect(), subClient.connect()]);
server.adapter(createAdapter(pubClient, subClient));
```

**Status:** Not implemented — flagged as requirement before horizontal scaling.
