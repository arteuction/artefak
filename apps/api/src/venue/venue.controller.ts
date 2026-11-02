import {
  Controller, Get, Param, Query,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation } from '@nestjs/swagger';
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
}
