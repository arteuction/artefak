import { Controller, Get, Param, Query } from '@nestjs/common';
import { ApiOperation, ApiTags } from '@nestjs/swagger';
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
}
