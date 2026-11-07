import { Module } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { CreateSettlement } from './create-settlement';
import { SettlementService } from './settlement.service';
import { WebhookHandlerService } from './webhook-handler.service';
import { StripeWebhookController } from './stripe-webhook.controller';

@Module({
  controllers: [StripeWebhookController],
  providers:   [PrismaService, CreateSettlement, SettlementService, WebhookHandlerService],
  exports:     [SettlementService, CreateSettlement, WebhookHandlerService],
})
export class SettlementModule {}
