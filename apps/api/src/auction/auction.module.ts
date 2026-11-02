import { Module } from '@nestjs/common';
import { BidConcurrencyService } from './bid-concurrency.service';
import { BidController } from './bid.controller';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [BidController],
  providers: [BidConcurrencyService, PrismaService],
  exports: [BidConcurrencyService],
})
export class AuctionModule {}
