import {
  Controller, Get, Param, Query,
  DefaultValuePipe, ParseIntPipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation } from '@nestjs/swagger';
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
}
