import {
  Controller, Get, Post, Param, Query, Body, UseGuards, HttpCode,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { DonationService, CreateDonationDto } from './donation.service';

@ApiTags('donations')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('donations')
export class DonationController {
  constructor(private readonly donationService: DonationService) {}

  @Get()
  @ApiOperation({ summary: 'List donations (own, or all for admin/operator)' })
  list(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.donationService.listDonations(user.id, user.role, page, perPage);
  }

  @Get(':donation')
  @ApiOperation({ summary: 'Get donation by ID' })
  getOne(
    @Param('donation', ParseIntPipe) donation: number,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.donationService.getDonation(donation, user.id, user.role);
  }

  @Post()
  @HttpCode(201)
  @ApiOperation({ summary: 'Make a donation' })
  store(@Body() body: CreateDonationDto, @CurrentUser() user: AuthenticatedUser) {
    return this.donationService.createDonation(user.id, body);
  }

  @Get('tax-receipt/:year')
  @ApiOperation({ summary: 'Get tax receipt for year' })
  taxReceipt(@Param('year', ParseIntPipe) year: number, @CurrentUser() user: AuthenticatedUser) {
    return this.donationService.taxReceipt(user.id, year);
  }
}
