import { Injectable, Logger } from '@nestjs/common';
import nodemailer, { Transporter } from 'nodemailer';

export interface SendEmailParams {
  to: string | string[];
  subject: string;
  html: string;
  text?: string;
}

@Injectable()
export class EmailService {
  private readonly logger = new Logger(EmailService.name);
  private readonly transport: Transporter | null;
  private readonly from: string;
  readonly enabled: boolean;

  constructor() {
    this.from = process.env.MAIL_FROM ?? 'noreply@arteuction.com';

    const host = process.env.MAIL_HOST;
    const port = parseInt(process.env.MAIL_PORT ?? '587', 10);
    const user = process.env.MAIL_USERNAME;
    const pass = process.env.MAIL_PASSWORD;

    this.enabled = !!(host && user && pass);

    if (this.enabled) {
      this.transport = nodemailer.createTransport({
        host,
        port,
        secure: port === 465,
        auth: { user, pass },
      });
    } else {
      this.transport = null;
    }
  }

  async send(params: SendEmailParams): Promise<void> {
    if (!this.transport) {
      this.logger.warn(`Email not configured — suppressing "${params.subject}" to ${params.to}`);
      return;
    }

    await this.transport.sendMail({
      from: this.from,
      to: Array.isArray(params.to) ? params.to.join(', ') : params.to,
      subject: params.subject,
      html: params.html,
      text: params.text,
    });
  }

  async sendRegistrationConfirmation(to: string, name: string): Promise<void> {
    await this.send({
      to,
      subject: 'Welcome to ARTeuCtion — confirm your email',
      html: `<h2>Welcome, ${name}!</h2><p>Thank you for registering with ARTeuCtion. Your account is now active.</p>`,
      text: `Welcome, ${name}! Thank you for registering with ARTeuCtion.`,
    });
  }

  async sendOutbidNotification(to: string, artworkTitle: string, newBidCents: number, currency: string): Promise<void> {
    const amount = (newBidCents / 100).toFixed(2);
    await this.send({
      to,
      subject: `You've been outbid on "${artworkTitle}"`,
      html: `<p>Someone placed a higher bid of <strong>${currency} ${amount}</strong> on <em>${artworkTitle}</em>.</p><p>Visit ARTeuCtion to place a new bid.</p>`,
      text: `You've been outbid on "${artworkTitle}". New highest bid: ${currency} ${amount}.`,
    });
  }

  async sendPaymentConfirmation(to: string, artworkTitle: string, amountCents: number, currency: string): Promise<void> {
    const amount = (amountCents / 100).toFixed(2);
    await this.send({
      to,
      subject: `Payment confirmed for "${artworkTitle}"`,
      html: `<p>Your payment of <strong>${currency} ${amount}</strong> for <em>${artworkTitle}</em> has been confirmed.</p>`,
      text: `Payment of ${currency} ${amount} confirmed for "${artworkTitle}".`,
    });
  }

  async sendBookPurchaseConfirmation(to: string, bookTitle: string): Promise<void> {
    await this.send({
      to,
      subject: `Your book is ready: "${bookTitle}"`,
      html: `<p>You can now access <em>${bookTitle}</em> in your ARTeuCtion library.</p>`,
      text: `"${bookTitle}" is now available in your library.`,
    });
  }

  async sendEmailVerification(to: string, token: string): Promise<void> {
    const appUrl = process.env.APP_URL ?? 'https://arteuction.com';
    const link = `${appUrl}/verify-email?token=${token}`;
    await this.send({
      to,
      subject: 'Verify your ARTeuCtion email address',
      html: `<p>Please verify your email address by clicking the link below:</p><p><a href="${link}">Verify email</a></p><p>This link expires in 24 hours.</p>`,
      text: `Verify your email: ${link} (expires in 24 hours)`,
    });
  }
}
