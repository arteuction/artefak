import {
  Controller, Get, Post, Patch, Put, Param, Query, Body,
  DefaultValuePipe, ParseIntPipe, UseGuards, HttpCode,
} from '@nestjs/common';
import { ApiBearerAuth, ApiTags, ApiOperation } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
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

  @Post()
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create exhibition' })
  create(@Body() dto: { title: string; slug: string; venue_id: number; starts_at: string; ends_at: string; description?: string }) {
    return this.exhibitionService.createExhibition(dto);
  }

  @Patch(':exhibition')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Update exhibition' })
  update(@Param('exhibition') exhibition: string, @Body() dto: { title?: string; description?: string; is_active?: boolean; starts_at?: string; ends_at?: string }) {
    return this.exhibitionService.updateExhibition(exhibition, dto);
  }

  @Put(':exhibition/sdg-tags')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Set SDG tags for exhibition' })
  tagSdgs(@Param('exhibition') exhibition: string, @Body() body: { sdg_numbers: number[] }) {
    return this.exhibitionService.tagSdgs(exhibition, body.sdg_numbers ?? []);
  }
}
