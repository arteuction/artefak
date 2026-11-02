import {
  Controller, Get, Param, Query,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation } from '@nestjs/swagger';
import { ImpactEventService } from './impact-event.service';

@ApiTags('impact-events')
@Controller('impact-events')
export class ImpactEventController {
  constructor(private readonly impactEventService: ImpactEventService) {}

  @Get()
  @ApiOperation({ summary: 'List impact events (public)' })
  list(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.impactEventService.listEvents(page, perPage);
  }

  @Get(':impactEvent')
  @ApiOperation({ summary: 'Get impact event by ID' })
  getOne(@Param('impactEvent', ParseIntPipe) impactEvent: number) {
    return this.impactEventService.getEvent(impactEvent);
  }
}
