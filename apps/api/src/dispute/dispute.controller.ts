import {
  Controller, Get, Post, Param, Body, Query,
  DefaultValuePipe, ParseIntPipe, UseGuards, HttpCode,
} from '@nestjs/common';
import { ApiTags, ApiOperation, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { DisputeService } from './dispute.service';

@ApiTags('disputes')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('disputes')
export class DisputeController {
  constructor(private readonly disputeService: DisputeService) {}

  @Get()
  @ApiOperation({ summary: 'List disputes (own, or all for admin/operator)' })
  list(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.disputeService.listDisputes(user.id, user.role, page, perPage);
  }

  @Get(':dispute')
  @ApiOperation({ summary: 'Get dispute by ID' })
  getOne(
    @Param('dispute', ParseIntPipe) dispute: number,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.disputeService.getDispute(dispute, user.id, user.role);
  }

  @Post()
  @HttpCode(201)
  @ApiOperation({ summary: 'Open a new dispute' })
  create(
    @CurrentUser() user: AuthenticatedUser,
    @Body() body: { type: string; description: string; art_lot_id?: number },
  ) {
    return this.disputeService.createDispute(user.id, body);
  }
}
