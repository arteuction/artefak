import {
  Controller, Post, Headers, RawBodyRequest, Req,
  HttpCode, Logger, BadRequestException, InternalServerErrorException,
} from '@nestjs/common';
import { ApiExcludeController } from '@nestjs/swagger';
import { Request } from 'express';
import { createHmac, timingSafeEqual } from 'node:crypto';
import { PrismaService } from '../prisma.service';
import { WebhookHandlerService } from './webhook-handler.service';

/**
 * Stripe webhook receiver.
 *
 * 1. Verifies Stripe-Signature (HMAC-SHA256, timing-safe compare).
 * 2. Inserts webhook_events with status 'received' (idempotent on stripe_event_id).
 * 3. Enqueues processing via WebhookHandlerService (synchronous in PoC;
 *    move to a queue worker before production cutover).
 * 4. Always returns 200 to Stripe — never expose internal errors.
 *
 * Mirrors Laravel StripeWebhookController exactly.
 */
@ApiExcludeController()
@Controller('stripe')
export class StripeWebhookController {
  private readonly logger = new Logger(StripeWebhookController.name);

  constructor(
    private readonly prisma:   PrismaService,
    private readonly handler:  WebhookHandlerService,
  ) {}

  @Post('webhook')
  @HttpCode(200)
  async handle(
    @Req() req: RawBodyRequest<Request>,
    @Headers('stripe-signature') sig: string,
  ): Promise<{ received: boolean }> {
    const secret  = process.env.STRIPE_WEBHOOK_SECRET;
    const rawBody = (req as any).rawBody as Buffer | undefined;

    if (!secret || !rawBody) {
      this.logger.error('STRIPE_WEBHOOK_SECRET not set or rawBody missing');
      return { received: true }; // return 200 so Stripe doesn't retry config errors
    }

    if (!sig || !this.verifySignature(rawBody, sig, secret)) {
      throw new BadRequestException('Invalid Stripe signature');
    }

    let event: any;
    try {
      event = JSON.parse(rawBody.toString('utf8'));
    } catch {
      throw new BadRequestException('Invalid JSON payload');
    }

    const stripeEventId = event.id;
    if (!stripeEventId) throw new BadRequestException('Missing event id');

    const now = new Date();

    // Insert webhook_event — idempotent on stripe_event_id
    let webhookEventId: number | null = null;
    try {
      const row = await this.prisma.webhook_events.create({
        data: {
          stripe_event_id: stripeEventId,
          type:            event.type,
          payload:         rawBody.toString('utf8'),
          status:          'received' as any,
          created_at:      now,
          updated_at:      now,
        },
        select: { id: true },
      });
      webhookEventId = Number(row.id);
    } catch (err: any) {
      if (err?.code === 'P2002') {
        // Already received — idempotent, return 200
        return { received: true };
      }
      this.logger.error(`Failed to store webhook event ${stripeEventId}: ${err?.message}`);
      return { received: true };
    }

    // Process (synchronous in PoC — move to queue before production)
    try {
      await this.handler.processWebhookEvent(webhookEventId);
    } catch (err: any) {
      this.logger.error(`Webhook processing failed for event ${stripeEventId}: ${err?.message}`);
      // Don't rethrow — Stripe must get 200 or it will retry
    }

    return { received: true };
  }

  /**
   * Stripe uses t=<timestamp>,v1=<sig> format.
   * We verify: HMAC-SHA256(secret, `${t}.${rawBody}`) === sig
   * Timing-safe comparison prevents timing attacks.
   */
  private verifySignature(rawBody: Buffer, header: string, secret: string): boolean {
    try {
      const parts     = Object.fromEntries(header.split(',').map((p) => p.split('=')));
      const timestamp = parts['t'];
      const expected  = parts['v1'];
      if (!timestamp || !expected) return false;

      const signed = `${timestamp}.${rawBody.toString('utf8')}`;
      const mac    = createHmac('sha256', secret).update(signed).digest('hex');

      return timingSafeEqual(Buffer.from(mac), Buffer.from(expected));
    } catch {
      return false;
    }
  }
}
