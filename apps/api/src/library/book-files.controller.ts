import { Controller, Get, Post, Patch, Delete, Param, Body, UseGuards, HttpCode } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const empty = () => ({ data: [] });
const stub = (extra?: object) => ({ status: 'ok', ...extra });

@ApiTags('books')
@UseGuards(JwtAuthGuard)
@ApiBearerAuth()
@Controller('books')
export class BookFilesController {
  @Get(':book/files') @ApiOperation({ summary: 'List book files' }) index() { return empty(); }

  @Post(':book/files')
  @HttpCode(201)
  @ApiOperation({ summary: 'Create book file metadata' })
  store(@Param('book') _book: string, @Body() _body: Record<string, unknown>) { return stub(); }

  @Patch(':book/files/:bookFile')
  @HttpCode(200)
  @ApiOperation({ summary: 'Update book file metadata' })
  update() { return stub(); }

  @Delete(':book/files/:bookFile')
  @HttpCode(200)
  @ApiOperation({ summary: 'Delete book file' })
  destroy() { return stub({ deleted: true }); }

  @Post(':book/files/upload-intent')
  @HttpCode(200)
  @ApiOperation({ summary: 'Get presigned upload URL for book file' })
  uploadIntent(@Param('book') _book: string, @Body() _body: Record<string, unknown>) {
    return { upload_url: 'https://stub.example.com/upload', key: 'stub/file', expires_in: 3600 };
  }
}

@ApiTags('book-files')
@UseGuards(JwtAuthGuard)
@ApiBearerAuth()
@Controller('book-files')
export class BookFileCompleteController {
  @Post(':bookFile/complete')
  @HttpCode(200)
  @ApiOperation({ summary: 'Complete book file upload' })
  complete(@Param('bookFile') _bookFile: string) { return stub({ confirmed: true }); }
}
