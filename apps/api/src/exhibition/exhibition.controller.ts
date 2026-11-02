import {
  Controller, Get, Param, Query,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation } from '@nestjs/swagger';
import { ExhibitionService } from './exhibition.service';

@ApiTags('exhibitions')
@Controller('exhibitions')
export class ExhibitionController {
  constructor(private readonly exhibitionService: ExhibitionService) {}

  @Get()
  @ApiOperation({ summary: 'List exhibitions' })
  list(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.exhibitionService.listExhibitions(page, perPage);
  }

  @Get(':exhibition')
  @ApiOperation({ summary: 'Get exhibition by ID or slug' })
  getOne(@Param('exhibition') exhibition: string) {
    return this.exhibitionService.getExhibition(exhibition);
  }
}
