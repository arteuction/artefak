/**
 * Phase 0.3U — BookCategoryService unit tests
 *
 * BC1  listCategories — returns empty stub
 * BC2  getCategory    — always throws NotFoundException (table not yet in schema)
 * BC3  getCategoryBooks — always throws NotFoundException
 */

import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { BookCategoryService } from './book-category.service';

describe('Phase 0.3U — BookCategoryService', () => {
  let svc: BookCategoryService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [BookCategoryService],
    }).compile();
    svc = module.get(BookCategoryService);
  });

  it('BC1: listCategories — returns empty paged stub', async () => {
    const result = await svc.listCategories();
    expect(result.data).toEqual([]);
    expect(result.meta.total).toBe(0);
  });

  it('BC2: getCategory — throws NotFoundException', () => {
    expect(() => svc.getCategory('some-category')).toThrow(NotFoundException);
  });

  it('BC3: getCategoryBooks — throws NotFoundException', () => {
    expect(() => svc.getCategoryBooks('some-category')).toThrow(NotFoundException);
  });
});
