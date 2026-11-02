import {
  Controller, Get, Post, Param, Query, Body,
  UseGuards, ParseIntPipe, DefaultValuePipe, HttpCode,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { SellNowService, CreateSellNowOfferDto } from './sell-now.service';

@ApiTags('sell-now')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller()
export class SellNowController {
  constructor(private readonly sellNowService: SellNowService) {}

  /** POST /api/v1/art-lots/:id/sell-now-offers — submit a buy-now offer */
  @Post('art-lots/:id/sell-now-offers')
  @HttpCode(201)
  @ApiOperation({ summary: 'Submit a sell-now (buy-now) offer for an art lot' })
  createOffer(
    @Param('id', ParseIntPipe) artLotId: number,
    @CurrentUser() user: AuthenticatedUser,
    @Body() dto: CreateSellNowOfferDto,
  ) {
    return this.sellNowService.createOffer(artLotId, user.id, dto);
  }

  /** GET /api/v1/me/sell-now-offers — list authenticated user's offers */
  @Get('me/sell-now-offers')
  @ApiOperation({ summary: "List authenticated user's sell-now offers" })
  listOffers(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.sellNowService.listUserOffers(user.id, page, perPage);
  }

  /** GET /api/v1/me/sell-now-offers/:id — single offer detail */
  @Get('me/sell-now-offers/:id')
  @ApiOperation({ summary: 'Get a specific sell-now offer (must be owned by authenticated user)' })
  getOffer(
    @Param('id', ParseIntPipe) offerId: number,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.sellNowService.getOffer(offerId, user.id);
  }

  @Post('sell-now-offers/:id/counter')
  @HttpCode(200)
  @ApiOperation({ summary: 'Counter a sell-now offer' })
  counter(
    @Param('id', ParseIntPipe) offerId: number,
    @CurrentUser() user: AuthenticatedUser,
    @Body() body: { counter_price_cents: number },
  ) {
    return this.sellNowService.counterOffer(offerId, user.id, body.counter_price_cents);
  }

  @Post('sell-now-offers/:id/accept')
  @HttpCode(200)
  @ApiOperation({ summary: 'Accept a sell-now offer' })
  accept(
    @Param('id', ParseIntPipe) offerId: number,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.sellNowService.acceptOffer(offerId, user.id);
  }

  @Post('sell-now-offers/:id/reject')
  @HttpCode(200)
  @ApiOperation({ summary: 'Reject a sell-now offer' })
  reject(
    @Param('id', ParseIntPipe) offerId: number,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.sellNowService.rejectOffer(offerId, user.id);
  }
}
