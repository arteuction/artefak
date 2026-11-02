import { Module } from '@nestjs/common';
import { BidConcurrencyService } from './bid-concurrency.service';
import { AuctionService } from './auction.service';
import { AuctionController } from './auction.controller';
import { BidController } from './bid.controller';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [AuctionController, BidController],
  providers: [AuctionService, BidConcurrencyService, PrismaService],
  exports: [AuctionService, BidConcurrencyService],
})
export class AuctionModule {}
