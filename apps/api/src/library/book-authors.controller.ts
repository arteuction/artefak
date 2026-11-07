import { Controller, Get, Post, Patch, Delete, Param, Body, UseGuards, HttpCode } from '@nestjs/common';
import { ApiBearerAuth, ApiOperation, ApiTags } from '@nestjs/swagger';
import { JwtAuthGuard } from '../auth/jwt-auth.guard';

const empty = () => ({ data: [] });
const stub = (extra?: object) => ({ status: 'ok', ...extra });

@ApiTags('books')
@Controller('books')
export class BookAuthorsController {
  @Get(':book/authors') @ApiOperation({ summary: 'List book authors' }) index() { return empty(); }

  @Post(':book/authors')
  @HttpCode(201)
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @ApiOperation({ summary: 'Add book author' })
  store(@Param('book') _book: string, @Body() _body: Record<string, unknown>) { return stub(); }

  @Patch(':book/authors/:bookAuthor')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @HttpCode(200)
  @ApiOperation({ summary: 'Update book author' })
  update(@Param('book') _book: string, @Param('bookAuthor') _author: string, @Body() _body: Record<string, unknown>) { return stub(); }

  @Delete(':book/authors/:bookAuthor')
  @UseGuards(JwtAuthGuard)
  @ApiBearerAuth()
  @HttpCode(200)
  @ApiOperation({ summary: 'Remove book author' })
  destroy() { return stub({ deleted: true }); }
}
