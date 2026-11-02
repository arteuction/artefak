import { Controller, Get, Param, Query, DefaultValuePipe, ParseIntPipe } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { SearchService } from './search.service';

@ApiTags('search')
@Controller('search')
export class SearchController {
  constructor(private readonly searchService: SearchService) {}

  @Get('artworks')
  @ApiOperation({ summary: 'Full-text search artworks' })
  searchArtworks(
    @Query('q') query = '',
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.searchService.searchArtworks(query, page, perPage);
  }

  @Get('artworks/:artwork')
  @ApiOperation({ summary: 'Get single artwork from search index' })
  showArtwork(@Param('artwork') _artwork: string) {
    return { id: 1, title: 'Stub', slug: '1', status: 'published' };
  }
}
