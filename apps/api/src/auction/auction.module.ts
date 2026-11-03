import { Module } from '@nestjs/common';
import { BidConcurrencyService } from './bid-concurrency.service';
import { AuctionService } from './auction.service';
import { AuctionController } from './auction.controller';
import { BidController } from './bid.controller';
import { AuctionItemsController } from './auction-items.controller';
import { PrismaService } from '../prisma.service';
import { StripeService } from '../stripe/stripe.service';

@Module({
  controllers: [AuctionController, BidController, AuctionItemsController],
  providers: [AuctionService, BidConcurrencyService, PrismaService, StripeService],
  exports: [AuctionService, BidConcurrencyService],
})
export class AuctionModule {}
