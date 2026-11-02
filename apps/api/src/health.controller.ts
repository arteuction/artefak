import { Controller, Get } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
import { AuctionService } from './auction/auction.service';

@ApiTags('health')
@Controller('health')
export class HealthController {
  constructor(private readonly auctionService: AuctionService) {}

  /** GET /api/v1/health — liveness + DB connectivity probe */
  @Get()
  @ApiOperation({ summary: 'Health check' })
  check() {
    return this.auctionService.health();
  }
}
