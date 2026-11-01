import {
  Controller,
  Get,
  Param,
  Query,
  NotFoundException,
  ParseIntPipe,
  DefaultValuePipe,
} from '@nestjs/common';
import { ApiOperation, ApiQuery, ApiTags } from '@nestjs/swagger';
import { LibraryService } from './library.service';

@ApiTags('library')
@Controller('books')
export class LibraryController {
  constructor(private readonly library: LibraryService) {}

  /** GET /api/v1/books — public catalogue, matches Laravel LibraryController#index shape */
  @Get()
  @ApiOperation({ summary: 'List published books' })
  @ApiQuery({ name: 'featured', required: false, type: Boolean })
  @ApiQuery({ name: 'is_free', required: false, type: Boolean })
  @ApiQuery({ name: 'language', required: false, type: String })
  @ApiQuery({ name: 'q', required: false, type: String })
  @ApiQuery({ name: 'page', required: false, type: Number })
  @ApiQuery({ name: 'per_page', required: false, type: Number })
  async index(
    @Query('featured') featured?: string,
    @Query('is_free') isFree?: string,
    @Query('language') language?: string,
    @Query('q') q?: string,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.library.listBooks({
      featured: featured === '1' || featured === 'true',
      is_free: isFree === '1' || isFree === 'true',
      language,
      q,
      page,
      perPage,
    });
  }

  /** GET /api/v1/books/:slug */
  @Get(':slug')
  @ApiOperation({ summary: 'Show a published book by slug' })
  async show(@Param('slug') slug: string) {
    const book = await this.library.getBook(slug);
    if (!book) throw new NotFoundException(`Book "${slug}" not found`);
    return book;
  }
}
