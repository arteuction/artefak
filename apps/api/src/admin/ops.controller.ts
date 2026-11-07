import { Controller, Get, Post, Param, ParseIntPipe, UseGuards, HttpCode } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { OpsService } from './ops.service';

const empty = () => ({ data: [] });

@ApiTags('ops')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('ops')
export class OpsController {
  constructor(private readonly ops: OpsService) {}

  @Get('summary') @ApiOperation({ summary: 'Operations summary' }) summary() { return this.ops.summary(); }
  @Get('pending-events') @ApiOperation({ summary: 'Pending events' }) pendingEvents() { return this.ops.pendingDomainEvents(); }
  @Get('failed-outbox') @ApiOperation({ summary: 'Failed outbox items' }) failedOutbox() { return this.ops.failedOutbox(); }
  @Get('open-reserves') @ApiOperation({ summary: 'Open reserves' }) openReserves() { return this.ops.openReserves(); }
  @Get('active-consignments') @ApiOperation({ summary: 'Active consignments (ops)' }) activeConsignments() { return empty(); }
  @Get('fiscal-year/:year?') @ApiOperation({ summary: 'Fiscal year summary' }) fiscalYear(@Param('year') _year?: string) { return empty(); }
  @Get('zkpo-report/:year?') @ApiOperation({ summary: 'ZKPO report' }) zkpoReport(@Param('year') _year?: string) { return empty(); }
  @Get('consumer-lag') @ApiOperation({ summary: 'Consumer lag' }) consumerLag() { return empty(); }
  @Post('transfer-outbox/:id/retry') @HttpCode(200) @ApiOperation({ summary: 'Retry transfer outbox item' })
  retryTransfer(@Param('id', ParseIntPipe) id: number) { return this.ops.retryOutboxItem(id); }
}
