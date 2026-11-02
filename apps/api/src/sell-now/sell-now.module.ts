import { Module } from '@nestjs/common';
import { SellNowController } from './sell-now.controller';
import { SellNowService } from './sell-now.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [SellNowController],
  providers: [SellNowService, PrismaService],
  exports: [SellNowService],
})
export class SellNowModule {}
