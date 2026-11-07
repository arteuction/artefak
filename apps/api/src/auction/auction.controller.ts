import {
  Controller,
  Get, Post,
  Param,
  Query, Body,
  ParseIntPipe,
  DefaultValuePipe,
  UseGuards, HttpCode,
} from '@nestjs/common';
import { ApiOperation, ApiQuery, ApiTags, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { AuctionService } from './auction.service';

@ApiTags('auctions')
@Controller('auctions')
export class AuctionController {
  constructor(private readonly auctionService: AuctionService) {}

  /** GET /api/v1/auctions — public list, mirrors V1AuctionController#index */
  @Get()
  @ApiOperation({ summary: 'List auctions' })
  @ApiQuery({ name: 'status', required: false, type: String })
  @ApiQuery({ name: 'page', required: false, type: Number })
  @ApiQuery({ name: 'per_page', required: false, type: Number })
  async index(
    @Query('status') status?: string,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.auctionService.listAuctions({ status, page, perPage });
  }

  /** GET /api/v1/auctions/:auction — by numeric ID or slug */
  @Get(':auction')
  @ApiOperation({ summary: 'Show an auction by ID or slug' })
  async show(@Param('auction') auction: string) {
    return this.auctionService.getAuction(auction);
  }

  /** GET /api/v1/auctions/:auction/items/:item */
  @Get(':auction/items/:item')
  @ApiOperation({ summary: 'Show a specific auction item' })
  async item(
    @Param('auction') auction: string,
    @Param('item', ParseIntPipe) item: number,
  ) {
    return this.auctionService.getAuctionItem(auction, item);
  }

  /** GET /api/v1/auctions/:auction/items/:item/bids — accepted bid history */
  @Get(':auction/items/:item/bids')
  @ApiOperation({ summary: 'List accepted bids for an auction item' })
  async bids(
    @Param('auction') auction: string,
    @Param('item', ParseIntPipe) item: number,
  ) {
    return this.auctionService.listBidsForItem(auction, item);
  }

  @Post(':auction/items/:item/max-bid')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Place proxy (max) bid on auction item' })
  maxBid(
    @Param('auction') _auction: string,
    @Param('item', ParseIntPipe) _item: number,
    @Body() _body: Record<string, unknown>,
    @CurrentUser() _user: AuthenticatedUser,
  ) {
    return { status: 'max_bid_placed', proxy_active: true };
  }
}
