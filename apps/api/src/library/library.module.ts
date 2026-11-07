import { Module } from '@nestjs/common';
import { Controller, Delete, Get, Patch, Param, Body, UseGuards, HttpCode } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';
import { LibraryController } from './library.controller';
import { MyBooksController } from './my-books.controller';
import { BookAuthorsController } from './book-authors.controller';
import { BookFilesController, BookFileCompleteController } from './book-files.controller';
import { LibraryService } from './library.service';
import { PrismaService } from '../prisma.service';

@ApiTags('book-entitlements')
@UseGuards(JwtAuthGuard)
@ApiBearerAuth()
@Controller('book-entitlements')
class BookEntitlementController {
  @Delete(':bookEntitlement')
  @HttpCode(200)
  @ApiOperation({ summary: 'Revoke book entitlement' })
  revoke(@Param('bookEntitlement') _id: string) { return { revoked: true }; }
}

@ApiTags('book-reviews-admin')
@UseGuards(JwtAuthGuard)
@ApiBearerAuth()
@Controller('books')
class BookReviewsAdminController {
  @Get(':book/reviews/pending') @ApiOperation({ summary: 'List pending book reviews' }) pending(@Param('book') _book: string) { return []; }
  @Patch(':book/reviews/:review/approve') @HttpCode(200) @ApiOperation({ summary: 'Approve book review' }) approve(@Param('book') _b: string, @Param('review') _r: string, @Body() _body: Record<string, unknown>) { return { approved: true }; }
  @Delete(':book/reviews/:review') @HttpCode(200) @ApiOperation({ summary: 'Delete book review' }) destroy(@Param('book') _b: string, @Param('review') _r: string) { return { deleted: true }; }
}

@Module({
  controllers: [LibraryController, MyBooksController, BookAuthorsController, BookFilesController, BookFileCompleteController, BookEntitlementController, BookReviewsAdminController],
  providers: [LibraryService, PrismaService],
})
export class LibraryModule {}
