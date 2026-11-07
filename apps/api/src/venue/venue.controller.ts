import {
  Controller, Get, Post, Patch, Param, Query, Body, UseGuards, HttpCode,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { VenueService } from './venue.service';

@ApiTags('venues')
@Controller('venues')
export class VenueController {
  constructor(private readonly venueService: VenueService) {}

  @Get()
  @ApiOperation({ summary: 'List venues' })
  list(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.venueService.listVenues(page, perPage);
  }

  @Get('map')
  @ApiOperation({ summary: 'Get venue map pins' })
  map() {
    return this.venueService.getVenueMap();
  }

  @Get(':venue')
  @ApiOperation({ summary: 'Get venue by ID or slug' })
  getOne(@Param('venue') venue: string) {
    return this.venueService.getVenue(venue);
  }

  @Post()
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create venue (admin)' })
  create(@Body() dto: Record<string, unknown>) {
    return this.venueService.createVenue(dto);
  }

  @Patch(':venue')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @HttpCode(200)
  @ApiOperation({ summary: 'Update venue (admin)' })
  update(@Param('venue') venue: string, @Body() dto: Record<string, unknown>) {
    return this.venueService.updateVenue(venue, dto);
  }
}
