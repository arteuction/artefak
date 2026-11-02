import { Controller, Get, Post, Patch, Param, Body, Query, DefaultValuePipe, ParseIntPipe, UseGuards, HttpCode } from '@nestjs/common';
import { ApiTags, ApiBearerAuth, ApiOperation } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { InstitutionService } from './institution.service';

@ApiTags('institutions')
@Controller('institutions')
export class InstitutionController {
  constructor(private readonly institutionService: InstitutionService) {}

  @Get()
  @ApiOperation({ summary: 'List institutions' })
  list(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.institutionService.listInstitutions(page, perPage);
  }

  @Get(':institution')
  @ApiOperation({ summary: 'Get one institution' })
  show(@Param('institution') id: string) {
    return this.institutionService.getInstitution(id);
  }

  @Post()
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create institution' })
  store(@Body() body: { name: string; slug: string; type?: string; country_code?: string; website_url?: string; contact_email?: string }) {
    return this.institutionService.createInstitution(body);
  }

  @Patch(':institution')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Update institution' })
  update(@Param('institution') id: string, @Body() body: { name?: string; country_code?: string; website_url?: string; contact_email?: string; is_active?: boolean }) {
    return this.institutionService.updateInstitution(id, body);
  }

  @Post(':institution/artworks')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Attach artwork to institution' })
  attachArtwork(@Param('institution') id: string, @Body() body: { artwork_id: number }) {
    return this.institutionService.attachArtwork(id, body.artwork_id);
  }
}
