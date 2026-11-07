import { Controller, Get, Param, Query, ParseIntPipe, DefaultValuePipe } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { ArtistService, ArtistsQuery } from './artist.service';

@ApiTags('artists')
@Controller('artists')
export class ArtistController {
  constructor(private readonly artistService: ArtistService) {}

  @Get()
  @ApiOperation({ summary: 'List approved artists' })
  list(@Query() query: ArtistsQuery) {
    return this.artistService.listArtists(query);
  }

  @Get(':artist')
  @ApiOperation({ summary: 'Get artist profile by ID or slug' })
  show(@Param('artist') artist: string) {
    return this.artistService.getArtist(artist);
  }

  @Get(':artist/artworks')
  @ApiOperation({ summary: "List an artist's artworks" })
  artworks(
    @Param('artist') artist: string,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.artistService.getArtistArtworks(artist, page, perPage);
  }
}
