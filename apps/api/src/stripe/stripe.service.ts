import { Injectable, Logger } from '@nestjs/common';
import Stripe from 'stripe';

export interface CheckoutLineItem {
  name: string;
  description?: string;
  amount_cents: number;
  currency: string;
  quantity?: number;
}

export interface CreateCheckoutParams {
  lineItems: CheckoutLineItem[];
  successUrl: string;
  cancelUrl: string;
  clientReferenceId?: string;
  customerEmail?: string;
  metadata?: Record<string, string>;
}

@Injectable()
export class StripeService {
  private readonly logger = new Logger(StripeService.name);
  private readonly stripe: Stripe | null;
  readonly enabled: boolean;

  constructor() {
    const secretKey = process.env.STRIPE_SECRET_KEY ?? '';
    this.enabled = secretKey.startsWith('sk_');
    this.stripe = this.enabled ? new Stripe(secretKey, { apiVersion: '2026-10-28.endive' }) : null;
  }

  async createCheckoutSession(params: CreateCheckoutParams): Promise<{ checkout_url: string | null; session_id: string | null }> {
    if (!this.stripe || !this.enabled) {
      this.logger.warn('STRIPE_SECRET_KEY not set — returning stub checkout session');
      return { checkout_url: null, session_id: null };
    }

    const session = await this.stripe.checkout.sessions.create({
      line_items: params.lineItems.map(item => ({
        price_data: {
          currency: item.currency.toLowerCase(),
          unit_amount: item.amount_cents,
          product_data: { name: item.name, description: item.description },
        },
        quantity: item.quantity ?? 1,
      })),
      mode: 'payment',
      success_url: params.successUrl,
      cancel_url: params.cancelUrl,
      client_reference_id: params.clientReferenceId,
      customer_email: params.customerEmail,
      metadata: params.metadata,
    });

    return { checkout_url: session.url, session_id: session.id };
  }

  async retrieveSession(sessionId: string): Promise<Stripe.Checkout.Session | null> {
    if (!this.stripe) return null;
    return this.stripe.checkout.sessions.retrieve(sessionId);
  }

  constructEvent(payload: string | Buffer, signature: string, secret: string): Stripe.Event {
    if (!this.stripe) throw new Error('Stripe not configured');
    return this.stripe.webhooks.constructEvent(payload, signature, secret);
  }

  async retrieveTransfer(transferId: string): Promise<{ transfer_id: string; amount: number } | null> {
    if (!this.stripe) return null;
    try {
      const transfer = await this.stripe.transfers.retrieve(transferId);
      return { transfer_id: transfer.id, amount: transfer.amount };
    } catch {
      return null; // Not found or API error — treat as "does not exist"
    }
  }

  async createTransfer(params: {
    amount_cents: number;
    currency: string;
    destination: string;
    transfer_group?: string;
    /** Stable key for at-most-once delivery — required in production */
    idempotency_key?: string;
    metadata?: Record<string, string>;
  }): Promise<{ transfer_id: string } | null> {
    if (!this.stripe) {
      this.logger.warn('Stripe not configured — skipping transfer');
      return null;
    }
    const requestOptions = params.idempotency_key
      ? { idempotencyKey: params.idempotency_key }
      : undefined;
    const transfer = await this.stripe.transfers.create(
      {
        amount: params.amount_cents,
        currency: params.currency.toLowerCase(),
        destination: params.destination,
        transfer_group: params.transfer_group,
        metadata: params.metadata,
      },
      requestOptions,
    );
    return { transfer_id: transfer.id };
  }
}
