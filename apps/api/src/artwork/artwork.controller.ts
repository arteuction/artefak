import { Controller, Get, Post, Patch, Param, Query, Body, UseGuards, HttpCode, ParseIntPipe } from '@nestjs/common';
import { ApiOperation, ApiTags, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { ArtworkService, ArtworksQuery } from './artwork.service';

@ApiTags('artworks')
@Controller('artworks')
export class ArtworkController {
  constructor(private readonly artworkService: ArtworkService) {}

  @Get()
  @ApiOperation({ summary: 'List artworks (public, non-deleted)' })
  list(@Query() query: ArtworksQuery) {
    return this.artworkService.listArtworks(query);
  }

  @Get(':artwork')
  @ApiOperation({ summary: 'Get artwork by ID or slug' })
  show(@Param('artwork') artwork: string) {
    return this.artworkService.getArtwork(artwork);
  }

  @Get(':artwork/lots')
  @ApiOperation({ summary: 'List art lots for an artwork' })
  lots(@Param('artwork') artwork: string) {
    return this.artworkService.listArtLots(artwork);
  }

  @Post()
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create artwork' })
  store(@CurrentUser() user: AuthenticatedUser, @Body() body: { title: string; slug: string; medium?: string; description?: string; year_created?: number; dimensions?: string }) {
    return this.artworkService.createArtwork(user.id, body);
  }

  @Patch(':artwork')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Update artwork' })
  update(@Param('artwork') artwork: string, @CurrentUser() user: AuthenticatedUser, @Body() body: { title?: string; description?: string; dimensions?: string; year_created?: number }) {
    return this.artworkService.updateArtwork(artwork, user.id, user.role, body);
  }

  @Post(':artwork/submit')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Submit artwork for review' })
  submit(@Param('artwork') artwork: string, @CurrentUser() user: AuthenticatedUser) {
    return this.artworkService.submitArtwork(artwork, user.id, user.role);
  }

  @Get(':artwork/evidence')
  @ApiOperation({ summary: 'List evidence for artwork' })
  listEvidence(@Param('artwork') artwork: string) {
    return this.artworkService.listEvidence(artwork);
  }

  @Post(':artwork/evidence')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Submit evidence for artwork' })
  createEvidence(@Param('artwork') artwork: string, @CurrentUser() user: AuthenticatedUser, @Body() body: { type: string; issuer?: string; notes?: string }) {
    return this.artworkService.createEvidence(artwork, user.id, body);
  }

  @Get(':artwork/sdg-claims')
  @ApiOperation({ summary: 'List SDG claims for artwork' })
  listSdgClaims(@Param('artwork') artwork: string) {
    return this.artworkService.listSdgClaims(artwork);
  }

  @Post(':artwork/sdg-claims')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create SDG claim for artwork' })
  createSdgClaim(@Param('artwork') artwork: string, @CurrentUser() user: AuthenticatedUser, @Body() body: { sdg_number: number; rationale: string }) {
    return this.artworkService.createSdgClaim(artwork, user.id, body);
  }

  @Get(':artwork/linked-art')
  @ApiOperation({ summary: 'Get Linked Art JSON-LD for artwork' })
  linkedArt(@Param('artwork') artwork: string) { return this.artworkService.getLinkedArt(artwork); }

  @Get(':artwork/edm')
  @ApiOperation({ summary: 'Get Europeana EDM for artwork' })
  edm(@Param('artwork') artwork: string) { return this.artworkService.getEdm(artwork); }

  @Get(':artwork/iiif/manifest')
  @ApiOperation({ summary: 'Get IIIF Presentation 3 manifest' })
  iiifManifest(@Param('artwork') artwork: string) { return this.artworkService.getIiifManifest(artwork); }

  @Get(':artwork/iiif/manifest2')
  @ApiOperation({ summary: 'Get IIIF Presentation 2 manifest' })
  iiifManifest2(@Param('artwork') artwork: string) { return this.artworkService.getIiifManifest(artwork); }

  @Get(':artwork/iiif/info.json')
  @ApiOperation({ summary: 'Get IIIF Image API info' })
  iiifInfo(@Param('artwork') artwork: string) { return this.artworkService.getIiifInfo(artwork); }

  @Get(':artwork/rights')
  @ApiOperation({ summary: 'Get rights statement for artwork' })
  getRights(@Param('artwork') artwork: string) { return this.artworkService.getRights(artwork); }

  @Patch(':artwork/rights')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Update rights statement for artwork' })
  updateRights(@Param('artwork') artwork: string, @CurrentUser() user: AuthenticatedUser, @Body() body: { rights_statement?: string; license_url?: string }) {
    return this.artworkService.updateRights(artwork, user.id, user.role, body);
  }

  @Get(':artwork/qr')
  @ApiOperation({ summary: 'Get QR code for artwork' })
  qr(@Param('artwork') artwork: string) { return this.artworkService.getQr(artwork); }

  @Get(':artwork/revisions')
  @ApiOperation({ summary: 'List revisions for artwork' })
  listRevisions(@Param('artwork') artwork: string) { return this.artworkService.listRevisions(artwork); }

  @Post(':artwork/revisions')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create revision for artwork' })
  createRevision(@Param('artwork') artwork: string, @CurrentUser() user: AuthenticatedUser, @Body() body: { title: string; medium?: string; description?: string; reason?: string }) {
    return this.artworkService.createRevision(artwork, user.id, body);
  }

  @Post(':artwork/revisions/:revision/activate')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Activate a revision' })
  activateRevision(@Param('artwork') artwork: string, @Param('revision', ParseIntPipe) revision: number, @CurrentUser() user: AuthenticatedUser) {
    return this.artworkService.activateRevision(artwork, revision, user.id, user.role);
  }

  @Get(':artwork/sdg-claims/:claim')
  @ApiOperation({ summary: 'Get a single SDG claim' })
  showSdgClaim(@Param('artwork') artwork: string, @Param('claim') claim: string) {
    return this.artworkService.getSdgClaim(artwork, claim);
  }

  @Post(':artwork/sdg-claims/:claim/review')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Review (approve/reject) an SDG claim' })
  reviewSdgClaim(@Param('artwork') artwork: string, @Param('claim') claim: string, @CurrentUser() user: AuthenticatedUser, @Body() body: { approved: boolean; notes?: string }) {
    return this.artworkService.reviewSdgClaim(artwork, claim, user.id, user.role, body);
  }

  @Get(':artwork/sales-history')
  @ApiOperation({ summary: 'Get sales history for artwork' })
  salesHistory(@Param('artwork') artwork: string) {
    return this.artworkService.getSalesHistory(artwork);
  }

  @Post(':artwork/images/presign')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Get presigned URL for artwork image upload' })
  presignImage(@Param('artwork') artwork: string, @CurrentUser() user: AuthenticatedUser, @Body() body: { filename: string; content_type: string }) {
    return this.artworkService.presignImage(artwork, user.id, body);
  }

  @Post(':artwork/images/confirm')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Confirm artwork image upload' })
  confirmImage(@Param('artwork') artwork: string, @CurrentUser() user: AuthenticatedUser, @Body() body: { key: string }) {
    return this.artworkService.confirmImage(artwork, user.id, body);
  }

  @Post(':artwork/images/derivatives')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Generate image derivatives for artwork' })
  generateDerivatives(@Param('artwork') artwork: string, @CurrentUser() user: AuthenticatedUser) {
    return this.artworkService.generateDerivatives(artwork, user.id, user.role);
  }

  @Post(':artwork/images/approve')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Approve and activate artwork image (admin/operator only)' })
  approveImage(
    @Param('artwork') artwork: string,
    @CurrentUser() user: AuthenticatedUser,
    @Body() body: { approved_key: string },
  ) {
    return this.artworkService.approveImage(artwork, user.role, body);
  }

  @Get(':artwork/images/url')
  @ApiOperation({ summary: 'Get presigned GET URL for approved artwork image' })
  getImageUrl(@Param('artwork') artwork: string) {
    return this.artworkService.getImageUrl(artwork);
  }
}
