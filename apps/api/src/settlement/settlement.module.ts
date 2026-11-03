import { Module } from '@nestjs/common';
import { PrismaService } from '../prisma.service';
import { CreateSettlement } from './create-settlement';
import { SettlementService } from './settlement.service';

@Module({
  providers: [PrismaService, CreateSettlement, SettlementService],
  exports:   [SettlementService, CreateSettlement],
})
export class SettlementModule {}
