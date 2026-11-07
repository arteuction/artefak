import { Controller, Get, Post, Param, Body, Query, DefaultValuePipe, ParseIntPipe, ParseIntPipe as PIP, UseGuards, HttpCode } from '@nestjs/common';
import { ApiTags, ApiBearerAuth, ApiOperation } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { ArtistApplicationService } from './artist-application.service';

@ApiTags('artist-applications')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('artist-applications')
export class ArtistApplicationController {
  constructor(private readonly artistApplicationService: ArtistApplicationService) {}

  @Get()
  @ApiOperation({ summary: 'List artist applications (admin: all; user: own)' })
  list(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.artistApplicationService.listApplications(user.id, user.role, page, perPage);
  }

  @Get(':application')
  @ApiOperation({ summary: 'Get one artist application' })
  show(@CurrentUser() user: AuthenticatedUser, @Param('application', ParseIntPipe) id: number) {
    return this.artistApplicationService.getApplication(id, user.id, user.role);
  }

  @Post()
  @HttpCode(201)
  @ApiOperation({ summary: 'Submit artist application' })
  store(@CurrentUser() user: AuthenticatedUser, @Body() body: { portfolio_url?: string; motivation?: string }) {
    return this.artistApplicationService.createApplication(user.id, body);
  }

  @Post(':application/approve')
  @HttpCode(200)
  @ApiOperation({ summary: 'Approve artist application (admin)' })
  approve(@CurrentUser() user: AuthenticatedUser, @Param('application', ParseIntPipe) id: number, @Body() body: { review_note?: string }) {
    return this.artistApplicationService.reviewApplication(id, user.id, 'approved', body.review_note);
  }

  @Post(':application/reject')
  @HttpCode(200)
  @ApiOperation({ summary: 'Reject artist application (admin)' })
  reject(@CurrentUser() user: AuthenticatedUser, @Param('application', ParseIntPipe) id: number, @Body() body: { review_note?: string }) {
    return this.artistApplicationService.reviewApplication(id, user.id, 'rejected', body.review_note);
  }
}
