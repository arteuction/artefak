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

  /**
   * Find and verify a transfer by transfer_group for crash-recovery reconciliation.
   *
   * transfer_group is a grouping hint, not a unique constraint at Stripe. We verify
   * that the match has the correct external_ref in metadata, plus matching destination,
   * currency and amount. If multiple transfers match or none match verification,
   * returns null and the caller must raise a reconciliation incident rather than
   * creating a new transfer.
   */
  async findTransferByGroup(params: {
    transferGroup: string;
    externalRef: string;
    amountCents: number;
    currency: string;
    destination: string;
  }): Promise<{ transfer_id: string; amount: number } | null> {
    if (!this.stripe) return null;
    try {
      const list = await this.stripe.transfers.list({
        transfer_group: params.transferGroup,
        limit: 10, // fetch more than 1 so we can detect unexpected duplicates
      });
      const { data } = list;
      if (data.length === 0) return null;

      const verified = data.filter(t =>
        t.metadata?.['external_ref'] === params.externalRef &&
        t.destination === params.destination &&
        t.currency === params.currency.toLowerCase() &&
        t.amount === params.amountCents,
      );

      if (verified.length === 1) {
        return { transfer_id: verified[0].id, amount: verified[0].amount };
      }

      if (verified.length > 1) {
        // Multiple verified matches: ambiguous state — do not proceed automatically
        this.logger.error(
          `[reconciliation] transfer_group=${params.transferGroup} external_ref=${params.externalRef} ` +
          `matched ${verified.length} transfers: ${verified.map(t => t.id).join(', ')} — ` +
          `MANUAL RECONCILIATION REQUIRED`
        );
        return null; // caller must not create a new transfer
      }

      // Transfers exist in this group but none match verification criteria —
      // log for investigation and do not proceed
      this.logger.error(
        `[reconciliation] transfer_group=${params.transferGroup} has ${data.length} transfer(s) ` +
        `but none match external_ref/amount/currency/destination — ` +
        `found: ${data.map(t => `${t.id}(${t.amount}${t.currency})`).join(', ')} — ` +
        `MANUAL RECONCILIATION REQUIRED`
      );
      return null;
    } catch {
      return null;
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
