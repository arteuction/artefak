import {
  Controller, Get, Post, Param, Body, Query,
  DefaultValuePipe, ParseIntPipe, UseGuards, HttpCode,
} from '@nestjs/common';
import { ApiTags, ApiOperation, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { ConsignmentService } from './consignment.service';

@ApiTags('consignments')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('consignments')
export class ConsignmentController {
  constructor(private readonly consignmentService: ConsignmentService) {}

  @Get()
  @ApiOperation({ summary: 'List consignments (own, or all for admin/operator)' })
  list(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.consignmentService.listConsignments(user.id, user.role, page, perPage);
  }

  @Get(':consignment')
  @ApiOperation({ summary: 'Get consignment by ID' })
  getOne(
    @Param('consignment', ParseIntPipe) consignment: number,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.consignmentService.getConsignment(consignment, user.id, user.role);
  }

  @Post()
  @HttpCode(201)
  @ApiOperation({ summary: 'Create a consignment' })
  create(
    @CurrentUser() user: AuthenticatedUser,
    @Body() body: { artwork_id: number; gallery_id?: number; commission_bps?: number },
  ) {
    return this.consignmentService.createConsignment(user.id, body);
  }
}
