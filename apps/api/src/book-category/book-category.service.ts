import { Injectable, NotFoundException } from '@nestjs/common';

// book_categories table does not exist in the schema yet; endpoints are stubbed
// until the table is added in a future migration.

@Injectable()
export class BookCategoryService {
  listCategories(_page = 1, _perPage = 20) {
    return Promise.resolve({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } });
  }

  getCategory(idOrSlug: string) {
    throw new NotFoundException(`Category ${idOrSlug} not found`);
  }

  getCategoryBooks(idOrSlug: string, _page = 1, _perPage = 20) {
    throw new NotFoundException(`Category ${idOrSlug} not found`);
  }
}
