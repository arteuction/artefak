import {
  Controller, Get, Post, Patch, Delete,
  Query, Body, Param, ParseIntPipe, DefaultValuePipe,
  UseGuards, HttpCode,
} from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { ProfileService } from './profile.service';
import { GovernanceService } from '../governance/governance.service';

@ApiTags('profile')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller('me')
export class ProfileController {
  constructor(
    private readonly profileService: ProfileService,
    private readonly governanceService: GovernanceService,
  ) {}

  @Get()
  @ApiOperation({ summary: 'Get authenticated user profile' })
  getProfile(@CurrentUser() user: AuthenticatedUser) {
    return {
      id: user.id,
      email: user.email,
      role: user.role,
      email_verified_at: user.emailVerifiedAt,
    };
  }

  @Get('bids')
  @ApiOperation({ summary: "List authenticated user's bids" })
  async getBids(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.profileService.getUserBids(user.id, page, perPage);
  }

  @Get('watchlist')
  @ApiOperation({ summary: "List authenticated user's watchlist" })
  getWatchlist(@CurrentUser() user: AuthenticatedUser) {
    return this.profileService.getWatchlist(user.id);
  }

  @Post('watchlist')
  @HttpCode(201)
  @ApiOperation({ summary: 'Add item to watchlist' })
  addToWatchlist(
    @CurrentUser() user: AuthenticatedUser,
    @Body() body: { subject_type: string; subject_id: number },
  ) {
    return this.profileService.addToWatchlist(user.id, body.subject_type, body.subject_id);
  }

  @Delete('watchlist/:id')
  @HttpCode(200)
  @ApiOperation({ summary: 'Remove item from watchlist' })
  removeFromWatchlist(
    @CurrentUser() user: AuthenticatedUser,
    @Param('id', ParseIntPipe) id: number,
  ) {
    return this.profileService.removeFromWatchlist(user.id, id);
  }

  @Get('collections')
  @ApiOperation({ summary: "List authenticated user's public collections" })
  getCollections(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.profileService.getUserCollections(user.id, page, perPage);
  }

  @Get('consignments')
  @ApiOperation({ summary: "List authenticated user's consignments (as consignor)" })
  getConsignments(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.profileService.getUserConsignments(user.id, page, perPage);
  }

  @Get('artworks')
  @ApiOperation({ summary: "List authenticated user's artworks" })
  getMyArtworks(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.profileService.getMyArtworks(user.id, page, perPage);
  }

  @Get('gallery-offers')
  @ApiOperation({ summary: "List authenticated user's gallery offers" })
  getGalleryOffers(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.profileService.getGalleryOffers(user.id, page, perPage);
  }

  @Get('won-items')
  @ApiOperation({ summary: "List authenticated user's won auction items" })
  getWonItems(
    @CurrentUser() user: AuthenticatedUser,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.profileService.getWonItems(user.id, page, perPage);
  }

  @Get('artist-profile')
  @ApiOperation({ summary: "Get authenticated user's artist profile" })
  getArtistProfile(@CurrentUser() user: AuthenticatedUser) {
    return this.profileService.getArtistProfile(user.id);
  }

  @Post('artist-profile')
  @HttpCode(201)
  @ApiOperation({ summary: "Create authenticated user's artist profile" })
  createArtistProfile(
    @CurrentUser() user: AuthenticatedUser,
    @Body() body: {
      display_name: string; slug: string; bio?: string;
      website?: string; instagram_handle?: string; terms_accepted_at?: string;
    },
  ) {
    return this.profileService.createArtistProfile(user.id, body);
  }

  @Patch('artist-profile')
  @ApiOperation({ summary: "Update authenticated user's artist profile" })
  updateArtistProfile(
    @CurrentUser() user: AuthenticatedUser,
    @Body() body: { display_name?: string; bio?: string; website?: string; instagram_handle?: string },
  ) {
    return this.profileService.updateArtistProfile(user.id, body);
  }

  @Get('consents')
  @ApiOperation({ summary: "List authenticated user's governance consents" })
  getConsents(@CurrentUser() user: AuthenticatedUser) {
    return this.governanceService.myConsents(user.id);
  }

  @Post('erasure-request')
  @HttpCode(200)
  @ApiOperation({ summary: 'Request account data erasure (GDPR Art. 17)' })
  requestErasure(
    @CurrentUser() user: AuthenticatedUser,
    @Body() body: { reason?: string },
  ) {
    return this.governanceService.requestErasure(user.id, body.reason);
  }

  @Get('export')
  @ApiOperation({ summary: 'Request personal data export (GDPR Art. 20)' })
  exportData(@CurrentUser() user: AuthenticatedUser) {
    return this.governanceService.exportData(user.id);
  }
}
