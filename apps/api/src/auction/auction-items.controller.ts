import { Controller, Get, Post, Body, Param, ParseIntPipe, UseGuards, HttpCode } from '@nestjs/common';
import { ApiTags, ApiBearerAuth, ApiOperation } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { AuctionService } from './auction.service';

@ApiTags('auction-items')
@Controller('auction-items')
export class AuctionItemsController {
  constructor(private readonly auctionService: AuctionService) {}

  @Get(':item/bid-history')
  @ApiOperation({ summary: 'Full bid history for auction item (public)' })
  bidHistory(@Param('item', ParseIntPipe) itemId: number) {
    return this.auctionService.getBidHistory(itemId);
  }

  @Post(':item/re-authorize')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Re-authorize payment for auction item' })
  reAuthorize(@Param('item', ParseIntPipe) itemId: number, @CurrentUser() _user: AuthenticatedUser) {
    return this.auctionService.reAuthorizeItem(itemId);
  }

  @Post(':item/ship')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Mark auction item as shipped' })
  ship(@Param('item', ParseIntPipe) itemId: number, @CurrentUser() _user: AuthenticatedUser) {
    return this.auctionService.shipItem(itemId);
  }

  @Post(':item/confirm-delivery')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Confirm delivery of auction item' })
  confirmDelivery(@Param('item', ParseIntPipe) itemId: number, @CurrentUser() _user: AuthenticatedUser) {
    return this.auctionService.confirmDelivery(itemId);
  }

  /**
   * POST /api/v1/auction-items/:item/checkout-session
   * Creates a Stripe checkout URL for the auction winner to complete payment.
   * Only callable by the winner; item must be in 'won' status.
   */
  @Post(':item/checkout-session')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create Stripe checkout session for auction item winner' })
  checkoutSession(
    @Param('item', ParseIntPipe) itemId: number,
    @Body() body: { success_url: string; cancel_url: string },
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.auctionService.createCheckoutSession(itemId, user.id, body);
  }
}
