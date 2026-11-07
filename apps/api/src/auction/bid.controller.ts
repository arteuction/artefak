import {
  Controller,
  Post,
  Param,
  Body,
  Req,
  UseGuards,
  HttpCode,
  HttpStatus,
  BadRequestException,
  UnprocessableEntityException,
  ParseIntPipe,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { Request } from 'express';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { BidConcurrencyService, BidRejected } from './bid-concurrency.service';

@ApiTags('auctions')
@ApiBearerAuth()
@Controller('auctions/:auction/items/:item/bids')
export class BidController {
  constructor(private readonly bidService: BidConcurrencyService) {}

  /**
   * POST /api/v1/auctions/:auction/items/:item/bids
   * Mirrors Laravel PlaceBid action — requires authenticated buyer.
   */
  @Post()
  @HttpCode(HttpStatus.CREATED)
  @UseGuards(JwtAuthGuard)
  async store(
    @Param('item', ParseIntPipe) auctionItemId: number,
    @Body() body: { amount_cents?: unknown },
    @Req() req: Request,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    const rawAmount = body?.amount_cents;
    if (rawAmount === undefined || rawAmount === null) {
      throw new BadRequestException('amount_cents is required');
    }

    const amountCents = BigInt(String(rawAmount));
    if (amountCents <= BigInt(0)) {
      throw new BadRequestException('amount_cents must be positive');
    }

    try {
      const result = await this.bidService.placeBid({
        auctionItemId,
        bidderId: user.id,
        amountCents,
        ipAddress: req.ip,
      });

      return {
        bid_id: result.bidId.toString(),
        amount_cents: result.amountCents.toString(),
        status: result.status,
      };
    } catch (err) {
      if (err instanceof BidRejected) {
        throw new UnprocessableEntityException(err.message);
      }
      throw err;
    }
  }
}
