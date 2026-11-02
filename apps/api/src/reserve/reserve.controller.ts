import { Controller, Get, Param, ParseIntPipe, UseGuards } from '@nestjs/common';
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
}
