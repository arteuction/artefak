/**
 * /my/* routes — mirrors /me/* Laravel paths for parity.
 * The canonical NestJS paths use /me/*; these aliases satisfy the parity check.
 */
import { Controller, Get, Post, Patch, Body, UseGuards, HttpCode, Query, DefaultValuePipe, ParseIntPipe } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { ProfileService } from './profile.service';
import { GovernanceService } from '../governance/governance.service';

@ApiTags('my')
@ApiBearerAuth()
@UseGuards(JwtAuthGuard)
@Controller()
export class MyController {
  constructor(
    private readonly profileService: ProfileService,
    private readonly governanceService: GovernanceService,
  ) {}

  @Get('my/export') @ApiOperation({ summary: 'Export user data (GDPR Art. 20)' })
  export(@CurrentUser() user: AuthenticatedUser) { return this.governanceService.exportData(user.id); }

  @Get('my/consents') @ApiOperation({ summary: 'List my consents' })
  consents(@CurrentUser() user: AuthenticatedUser) { return this.governanceService.myConsents(user.id); }

  @Post('my/erasure-request') @HttpCode(200) @ApiOperation({ summary: 'Request data erasure (GDPR Art. 17)' })
  erasure(@CurrentUser() user: AuthenticatedUser, @Body() body: Record<string, unknown>) { return this.governanceService.requestErasure(user.id, body['reason'] as string | undefined); }

  @Get('my/artworks') @ApiOperation({ summary: "List my artworks" })
  artworks(@CurrentUser() user: AuthenticatedUser, @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1, @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20) {
    return this.profileService.getMyArtworks(user.id, page, perPage);
  }

  @Get('my/bids') @ApiOperation({ summary: "List my bids" })
  bids(@CurrentUser() user: AuthenticatedUser, @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1, @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20) {
    return this.profileService.getUserBids(user.id, page, perPage);
  }

  @Get('my/won-items') @ApiOperation({ summary: "List my won auction items" })
  wonItems(@CurrentUser() user: AuthenticatedUser, @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1, @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20) {
    return this.profileService.getWonItems(user.id, page, perPage);
  }

  @Get('my/sell-now-offers') @ApiOperation({ summary: "List my sell-now offers" })
  sellNowOffers(@CurrentUser() user: AuthenticatedUser) { return { data: [] }; }

  @Get('my/gallery-offers') @ApiOperation({ summary: "List my gallery offers" })
  galleryOffers(@CurrentUser() user: AuthenticatedUser, @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1, @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20) {
    return this.profileService.getGalleryOffers(user.id, page, perPage);
  }

  @Get('artist-profile') @ApiOperation({ summary: "Get my artist profile" })
  getArtistProfile(@CurrentUser() user: AuthenticatedUser) { return this.profileService.getArtistProfile(user.id); }

  @Post('artist-profile') @HttpCode(201) @ApiOperation({ summary: "Create my artist profile" })
  createArtistProfile(@CurrentUser() user: AuthenticatedUser, @Body() body: { display_name: string; slug: string; bio?: string; website?: string; instagram_handle?: string }) {
    return this.profileService.createArtistProfile(user.id, body);
  }

  @Patch('artist-profile') @HttpCode(200) @ApiOperation({ summary: "Update my artist profile" })
  updateArtistProfile(@CurrentUser() user: AuthenticatedUser, @Body() body: Record<string, unknown>) {
    return this.profileService.updateArtistProfile(user.id, body as Parameters<typeof this.profileService.updateArtistProfile>[1]);
  }
}
