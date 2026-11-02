import {
  Controller, Get, Post, Patch, Param, Query, Body, UseGuards, HttpCode,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { ArtLotService } from './art-lot.service';

@ApiTags('art-lots')
@Controller('art-lots')
export class ArtLotController {
  constructor(private readonly artLotService: ArtLotService) {}

  @Get()
  @ApiOperation({ summary: 'List art lots' })
  list(
    @Query('status') status?: string,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.artLotService.listArtLots({ status, page, perPage });
  }

  @Get(':artLot')
  @ApiOperation({ summary: 'Get art lot by ID or slug' })
  getOne(@Param('artLot') artLot: string) {
    return this.artLotService.getArtLot(artLot);
  }

  @Get(':artLot/bids')
  @ApiOperation({ summary: 'List accepted bids for art lot' })
  getBids(@Param('artLot') artLot: string) {
    return this.artLotService.listBids(artLot);
  }

  @Get(':artLot/provenance')
  @ApiOperation({ summary: 'Get provenance chain for art lot' })
  getProvenance(@Param('artLot') artLot: string) {
    return this.artLotService.getProvenance(artLot);
  }

  @Get(':artLot/sell-now-offers')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'List sell-now offers for art lot' })
  getSellNowOffers(@Param('artLot') artLot: string, @CurrentUser() user: AuthenticatedUser) {
    return this.artLotService.listSellNowOffers(artLot, user.id, user.role);
  }

  @Post()
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create art lot' })
  create(@Body() dto: Record<string, unknown>, @CurrentUser() user: AuthenticatedUser) {
    return this.artLotService.createArtLot(user.id, user.role, dto);
  }

  @Patch(':artLot')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @HttpCode(200)
  @ApiOperation({ summary: 'Update art lot' })
  update(@Param('artLot') artLot: string, @Body() dto: Record<string, unknown>, @CurrentUser() user: AuthenticatedUser) {
    return this.artLotService.updateArtLot(artLot, user.id, user.role, dto);
  }

  @Post(':artLot/purchase-now')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @HttpCode(200)
  @ApiOperation({ summary: 'Purchase art lot at buy-now price' })
  purchaseNow(@Param('artLot') artLot: string, @CurrentUser() user: AuthenticatedUser) {
    return this.artLotService.purchaseNow(artLot, user.id);
  }

  @Post(':artLot/transitions/:transition')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @HttpCode(200)
  @ApiOperation({ summary: 'Trigger art lot state transition' })
  transition(@Param('artLot') artLot: string, @Param('transition') transition: string, @CurrentUser() user: AuthenticatedUser) {
    return this.artLotService.transition(artLot, transition, user.id, user.role);
  }

}
