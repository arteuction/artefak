import {
  Controller, Get, Post, Param, Query, Body, UseGuards, HttpCode,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
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

  @Get(':impactEvent/evidence')
  @ApiOperation({ summary: 'List evidence for impact event' })
  listEvidence(@Param('impactEvent') _impactEvent: string) { return { data: [] }; }

  @Post(':impactEvent/evidence')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Attach evidence to impact event' })
  createEvidence(@Param('impactEvent') _impactEvent: string, @Body() _body: Record<string, unknown>) { return { id: 1, created_at: new Date().toISOString() }; }
}
