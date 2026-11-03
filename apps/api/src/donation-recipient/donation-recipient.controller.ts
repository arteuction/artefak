import {
  Controller, Get, Post, Patch, Param, Query, Body, UseGuards, HttpCode,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { DonationRecipientService } from './donation-recipient.service';

@ApiTags('donation-recipients')
@Controller('donation-recipients')
export class DonationRecipientController {
  constructor(private readonly donationRecipientService: DonationRecipientService) {}

  @Get()
  @ApiOperation({ summary: 'List active donation recipients (public)' })
  list(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.donationRecipientService.listRecipients(page, perPage);
  }

  @Get(':donationRecipient')
  @ApiOperation({ summary: 'Get donation recipient by ID' })
  getOne(@Param('donationRecipient', ParseIntPipe) donationRecipient: number) {
    return this.donationRecipientService.getRecipient(donationRecipient);
  }

  @Post()
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create donation recipient (admin)' })
  store(@Body() body: { name: string; legal_type?: string; eligibility_basis?: string; deduction_bps?: number }) {
    return this.donationRecipientService.createRecipient(body);
  }

  @Patch(':donationRecipient')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @HttpCode(200)
  @ApiOperation({ summary: 'Update donation recipient (admin)' })
  update(@Param('donationRecipient', ParseIntPipe) id: number, @Body() body: { name?: string; legal_type?: string; deduction_bps?: number; status?: string }) {
    return this.donationRecipientService.updateRecipient(id, body);
  }
}
