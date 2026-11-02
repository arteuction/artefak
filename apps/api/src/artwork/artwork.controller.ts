import { Controller, Get, Post, Patch, Param, Query, Body, UseGuards, HttpCode } from '@nestjs/common';
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
}
