import {
  Controller, Get, Param, Query,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation } from '@nestjs/swagger';
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
}
