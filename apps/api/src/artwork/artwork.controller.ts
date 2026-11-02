import { Controller, Get, Param, Query } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { ArtworkService, ArtworksQuery } from './artwork.service';

@ApiTags('artworks')
@Controller('artworks')
export class ArtworkController {
  constructor(private readonly artworkService: ArtworkService) {}

  @Get()
  @ApiOperation({ summary: 'List artworks (public, non-deleted)' })
  list(@Query() query: ArtworksQuery) {
    return this.artworkService.listArtworks(query);
  }

  @Get(':artwork')
  @ApiOperation({ summary: 'Get artwork by ID or slug' })
  show(@Param('artwork') artwork: string) {
    return this.artworkService.getArtwork(artwork);
  }

  @Get(':artwork/lots')
  @ApiOperation({ summary: 'List art lots for an artwork' })
  lots(@Param('artwork') artwork: string) {
    return this.artworkService.listArtLots(artwork);
  }
}
