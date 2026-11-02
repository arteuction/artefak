import {
  Controller,
  Get,
  Param,
  Query,
  ParseIntPipe,
  DefaultValuePipe,
} from '@nestjs/common';
import { ApiOperation, ApiQuery, ApiTags } from '@nestjs/swagger';
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
}
