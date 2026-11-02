import { Controller, Get, Param, Query } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
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
}
