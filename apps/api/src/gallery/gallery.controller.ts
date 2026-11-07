import { Controller, Get, Post, Patch, Delete, Param, Query, Body, UseGuards, HttpCode, ParseIntPipe } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { GalleryService, GalleriesQuery } from './gallery.service';

@ApiTags('galleries')
@Controller('galleries')
export class GalleryController {
  constructor(private readonly galleryService: GalleryService) {}

  @Get()
  @ApiOperation({ summary: 'List active galleries' })
  list(@Query() query: GalleriesQuery) {
    return this.galleryService.listGalleries(query);
  }

  @Get(':gallery')
  @ApiOperation({ summary: 'Get gallery by ID or slug' })
  show(@Param('gallery') gallery: string) {
    return this.galleryService.getGallery(gallery);
  }

  @Get(':gallery/lots')
  @ApiOperation({ summary: 'List art lots for a gallery' })
  lots(@Param('gallery') gallery: string) {
    return this.galleryService.listGalleryLots(gallery);
  }

  @Get(':gallery/profile')
  @ApiOperation({ summary: 'Get gallery profile with staff' })
  profile(@Param('gallery') gallery: string) {
    return this.galleryService.getGalleryProfile(gallery);
  }

  @Get(':gallery/staff')
  @ApiOperation({ summary: 'List gallery staff' })
  staff(@Param('gallery') gallery: string) {
    return this.galleryService.listStaff(gallery);
  }

  @Post()
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create a gallery' })
  create(@CurrentUser() _user: AuthenticatedUser, @Body() dto: { name: string; slug: string; type?: string; website?: string; contact_email?: string; legal_name?: string }) {
    return this.galleryService.createGallery(dto);
  }

  @Patch(':gallery')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Update a gallery' })
  update(
    @Param('gallery') gallery: string,
    @CurrentUser() user: AuthenticatedUser,
    @Body() dto: { name?: string; website?: string; contact_email?: string; legal_name?: string },
  ) {
    return this.galleryService.updateGallery(gallery, user.id, user.role, dto);
  }

  @Post(':gallery/staff')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Add staff to gallery' })
  addStaff(
    @Param('gallery') gallery: string,
    @CurrentUser() user: AuthenticatedUser,
    @Body() dto: { user_id: number; role: string },
  ) {
    return this.galleryService.addStaff(gallery, user.id, user.role, dto);
  }

  @Patch(':gallery/staff/:staffMember')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Update gallery staff member' })
  updateStaff(
    @Param('gallery') gallery: string,
    @Param('staffMember', ParseIntPipe) staffId: number,
    @CurrentUser() user: AuthenticatedUser,
    @Body() dto: { role?: string; status?: string },
  ) {
    return this.galleryService.updateStaff(gallery, staffId, user.id, user.role, dto);
  }

  @Delete(':gallery/staff/:user')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Remove staff from gallery' })
  removeStaff(
    @Param('gallery') gallery: string,
    @Param('user', ParseIntPipe) staffUserId: number,
    @CurrentUser() user: AuthenticatedUser,
  ) {
    return this.galleryService.removeStaff(gallery, staffUserId, user.id, user.role);
  }
}
