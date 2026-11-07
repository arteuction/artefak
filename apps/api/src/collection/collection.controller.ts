import { Controller, Get, Post, Patch, Delete, Param, Query, Body, UseGuards, HttpCode, ParseIntPipe } from '@nestjs/common';
import { ApiOperation, ApiTags, ApiBearerAuth } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { CurrentUser } from '../auth/current-user.decorator';
import { AuthenticatedUser } from '../auth/auth.types';
import { CollectionService, CollectionsQuery } from './collection.service';

@ApiTags('collections')
@Controller('collections')
export class CollectionController {
  constructor(private readonly collectionService: CollectionService) {}

  @Get()
  @ApiOperation({ summary: 'List public collections' })
  list(@Query() query: CollectionsQuery) {
    return this.collectionService.listPublicCollections(query);
  }

  @Get(':collection')
  @ApiOperation({ summary: 'Get public collection by ID or slug' })
  show(@Param('collection') collection: string) {
    return this.collectionService.getCollection(collection);
  }

  @Get(':collection/artworks')
  @ApiOperation({ summary: "List artworks in a public collection" })
  artworks(@Param('collection') collection: string) {
    return this.collectionService.getCollectionArtworks(collection);
  }

  @Post()
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Create a collection' })
  store(@CurrentUser() user: AuthenticatedUser, @Body() body: { title: string; slug: string; description?: string; visibility?: string }) {
    return this.collectionService.createCollection(user.id, body);
  }

  @Patch(':collection')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Update a collection' })
  update(@Param('collection') collection: string, @CurrentUser() user: AuthenticatedUser, @Body() body: { title?: string; description?: string; visibility?: string }) {
    return this.collectionService.updateCollection(collection, user.id, user.role, body);
  }

  @Delete(':collection')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Delete a collection' })
  destroy(@Param('collection') collection: string, @CurrentUser() user: AuthenticatedUser) {
    return this.collectionService.deleteCollection(collection, user.id, user.role);
  }

  @Post(':collection/artworks')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Add artwork to collection' })
  addArtwork(@Param('collection') collection: string, @CurrentUser() user: AuthenticatedUser, @Body() body: { artwork_id: number }) {
    return this.collectionService.addArtwork(collection, user.id, user.role, body.artwork_id);
  }

  @Delete(':collection/artworks/:artwork')
  @HttpCode(200)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Remove artwork from collection' })
  removeArtwork(@Param('collection') collection: string, @Param('artwork', ParseIntPipe) artworkId: number, @CurrentUser() user: AuthenticatedUser) {
    return this.collectionService.removeArtwork(collection, user.id, user.role, artworkId);
  }
}
