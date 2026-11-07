import {
  Controller, Get, Param, Query,
  DefaultValuePipe, ParseIntPipe, UseGuards,
} from '@nestjs/common';
import { ApiTags, ApiOperation, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { OwnershipTransferService } from './ownership-transfer.service';

@ApiTags('ownership-transfers')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('ownership-transfers')
export class OwnershipTransferController {
  constructor(private readonly ownershipTransferService: OwnershipTransferService) {}

  @Get()
  @ApiOperation({ summary: 'List ownership transfers (own, or all for admin/operator)' })
  list(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.ownershipTransferService.listTransfers(user.id, user.role, page, perPage);
  }

  @Get(':ownershipTransfer')
  @ApiOperation({ summary: 'Get ownership transfer by ID' })
  getOne(
    @Param('ownershipTransfer', ParseIntPipe) ownershipTransfer: number,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.ownershipTransferService.getTransfer(ownershipTransfer, user.id, user.role);
  }
}
