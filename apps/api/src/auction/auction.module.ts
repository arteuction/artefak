import { Module } from '@nestjs/common';
import { BidConcurrencyService } from './bid-concurrency.service';
import { PrismaService } from '../prisma.service';

@Module({
  providers: [BidConcurrencyService, PrismaService],
  exports: [BidConcurrencyService],
})
export class AuctionModule {}
