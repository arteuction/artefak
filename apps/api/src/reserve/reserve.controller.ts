import { Controller, Get, Post, Param, Body, ParseIntPipe, UseGuards, HttpCode } from '@nestjs/common';
import { ApiTags, ApiOperation, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { ReserveService } from './reserve.service';

@ApiTags('reserves')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('reserves')
export class ReserveController {
  constructor(private readonly reserveService: ReserveService) {}

  @Get(':reserve')
  @ApiOperation({ summary: 'Get reserve by ID (seller decision after reserve not met)' })
  getOne(@Param('reserve', ParseIntPipe) reserve: number) {
    return this.reserveService.getReserve(reserve);
  }

  @Post(':reserve/waive')
  @HttpCode(200)
  @ApiOperation({ summary: 'Waive reserve (sell at highest bid)' })
  waive(@Param('reserve', ParseIntPipe) _reserve: number) {
    return { status: 'waived', waived_at: new Date().toISOString() };
  }

  @Post(':reserve/counter-offer')
  @HttpCode(200)
  @ApiOperation({ summary: 'Make counter-offer after reserve not met' })
  counterOffer(@Param('reserve', ParseIntPipe) _reserve: number, @Body() _body: Record<string, unknown>) {
    return { status: 'counter_offered', counter_offer_at: new Date().toISOString() };
  }
}
