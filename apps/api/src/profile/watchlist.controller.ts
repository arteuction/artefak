import {
  Controller, Get, Post, Delete, Param, Body, ParseIntPipe,
  UseGuards, Req,
} from '@nestjs/common';
import { ApiTags, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { ProfileService } from './profile.service';

@ApiTags('watchlist')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('watchlist')
export class WatchlistController {
  constructor(private readonly profile: ProfileService) {}

  /** GET /watchlist */
  @Get()
  async list(@Req() req: any) {
    return this.profile.getWatchlist(req.user.sub);
  }

  /** POST /watchlist */
  @Post()
  async add(
    @Req() req: any,
    @Body() body: { subject_type: string; subject_id: number },
  ) {
    return this.profile.addToWatchlist(req.user.sub, body.subject_type, body.subject_id);
  }

  /** DELETE /watchlist/:id */
  @Delete(':id')
  async remove(@Req() req: any, @Param('id', ParseIntPipe) id: number) {
    return this.profile.removeFromWatchlist(req.user.sub, id);
  }
}
