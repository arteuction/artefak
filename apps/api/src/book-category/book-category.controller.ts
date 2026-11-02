import { Controller, Get, Param, Query, DefaultValuePipe, ParseIntPipe } from '@nestjs/common';
import { ApiTags, ApiOperation } from '@nestjs/swagger';
import { BookCategoryService } from './book-category.service';

@ApiTags('book-categories')
@Controller('book-categories')
export class BookCategoryController {
  constructor(private readonly bookCategoryService: BookCategoryService) {}

  @Get()
  @ApiOperation({ summary: 'List book categories' })
  list(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.bookCategoryService.listCategories(page, perPage);
  }

  @Get(':category')
  @ApiOperation({ summary: 'Get book category by ID or slug' })
  show(@Param('category') id: string) {
    return this.bookCategoryService.getCategory(id);
  }

  @Get(':category/books')
  @ApiOperation({ summary: 'List books in a category' })
  books(
    @Param('category') id: string,
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.bookCategoryService.getCategoryBooks(id, page, perPage);
  }
}
