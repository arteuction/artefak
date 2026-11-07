#!/usr/bin/env ts-node
/**
 * Domain scaffolding generator
 * Usage: npm run generate:domain -- <name>
 *
 * Creates the full NestJS domain structure under src/<name>/
 *   module, controller, service, DTOs, spec
 */

import { mkdirSync, writeFileSync, existsSync } from 'fs';
import { join } from 'path';

const name = process.argv[2];
if (!name) {
  console.error('Usage: npm run generate:domain -- <name>');
  process.exit(1);
}

const pascal = name.replace(/(^\w|-\w)/g, s => s.replace('-', '').toUpperCase());
const camel = pascal[0].toLowerCase() + pascal.slice(1);
const kebab = name.toLowerCase().replace(/\s+/g, '-');

const srcDir = join(import.meta.dirname ?? __dirname, '..', 'src', kebab);

if (existsSync(srcDir)) {
  console.error(`Directory already exists: ${srcDir}`);
  process.exit(1);
}

mkdirSync(srcDir, { recursive: true });
mkdirSync(join(srcDir, 'dto'), { recursive: true });

// ── module ────────────────────────────────────────────────────────────────────
writeFileSync(join(srcDir, `${kebab}.module.ts`), `import { Module } from '@nestjs/common';
import { ${pascal}Controller } from './${kebab}.controller';
import { ${pascal}Service } from './${kebab}.service';
import { PrismaService } from '../prisma.service';

@Module({
  controllers: [${pascal}Controller],
  providers: [${pascal}Service, PrismaService],
  exports: [${pascal}Service],
})
export class ${pascal}Module {}
`);

// ── service ───────────────────────────────────────────────────────────────────
writeFileSync(join(srcDir, `${kebab}.service.ts`), `import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class ${pascal}Service {
  constructor(private readonly prisma: PrismaService) {}

  async list(page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const [data, total] = await this.prisma.$transaction([
      // TODO: replace with correct Prisma model
      Promise.resolve([]),
      Promise.resolve(0),
    ]);
    return {
      data,
      meta: {
        current_page: page,
        last_page: Math.max(1, Math.ceil(Number(total) / perPage)),
        per_page: perPage,
        total: Number(total),
      },
    };
  }

  async getById(id: number) {
    // TODO: replace with correct Prisma model
    throw new NotFoundException(\`${pascal} \${id} not found\`);
  }
}
`);

// ── controller ────────────────────────────────────────────────────────────────
writeFileSync(join(srcDir, `${kebab}.controller.ts`), `import {
  Controller, Get, Param, ParseIntPipe, Query,
  DefaultValuePipe,
} from '@nestjs/common';
import { ApiTags, ApiOperation } from '@nestjs/swagger';
import { ${pascal}Service } from './${kebab}.service';

@ApiTags('${kebab}')
@Controller('${kebab}s')
export class ${pascal}Controller {
  constructor(private readonly ${camel}Service: ${pascal}Service) {}

  @Get()
  @ApiOperation({ summary: 'List ${kebab}s' })
  list(
    @Query('page', new DefaultValuePipe(1), ParseIntPipe) page = 1,
    @Query('per_page', new DefaultValuePipe(20), ParseIntPipe) perPage = 20,
  ) {
    return this.${camel}Service.list(page, perPage);
  }

  @Get(':id')
  @ApiOperation({ summary: 'Get ${kebab} by ID' })
  getOne(@Param('id', ParseIntPipe) id: number) {
    return this.${camel}Service.getById(id);
  }
}
`);

// ── dto ───────────────────────────────────────────────────────────────────────
writeFileSync(join(srcDir, 'dto', `create-${kebab}.dto.ts`), `export class Create${pascal}Dto {
  // TODO: add fields
}
`);

writeFileSync(join(srcDir, 'dto', `update-${kebab}.dto.ts`), `export class Update${pascal}Dto {
  // TODO: add fields
}
`);

// ── spec ──────────────────────────────────────────────────────────────────────
writeFileSync(join(srcDir, `${kebab}.controller.spec.ts`), `import { Test, TestingModule } from '@nestjs/testing';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { ${pascal}Controller } from './${kebab}.controller';
import { ${pascal}Service } from './${kebab}.service';

const mock${pascal}Service = {
  list: async () => ({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20, total: 0 } }),
  getById: async () => ({ id: 1 }),
};

describe('${pascal}Controller', () => {
  let app: NestFastifyApplication;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [${pascal}Controller],
      providers: [{ provide: ${pascal}Service, useValue: mock${pascal}Service }],
    }).compile();

    app = module.createNestApplication<NestFastifyApplication>(new FastifyAdapter());
    app.setGlobalPrefix('api/v1');
    await app.init();
    await app.getHttpAdapter().getInstance().ready();
  });

  afterAll(async () => { await app.close(); });

  it('${pascal[0].toUpperCase()}1: GET /${kebab}s returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/${kebab}s' });
    expect(res.statusCode).toBe(200);
    const body = JSON.parse(res.payload);
    expect(body).toHaveProperty('data');
    expect(body).toHaveProperty('meta');
  });

  it('${pascal[0].toUpperCase()}2: GET /${kebab}s/:id returns 200', async () => {
    const res = await app.inject({ method: 'GET', url: '/api/v1/${kebab}s/1' });
    expect(res.statusCode).toBe(200);
  });
});
`);

console.log(`✓ Scaffolded domain: ${kebab}`);
console.log(`  src/${kebab}/${kebab}.module.ts`);
console.log(`  src/${kebab}/${kebab}.service.ts`);
console.log(`  src/${kebab}/${kebab}.controller.ts`);
console.log(`  src/${kebab}/dto/create-${kebab}.dto.ts`);
console.log(`  src/${kebab}/dto/update-${kebab}.dto.ts`);
console.log(`  src/${kebab}/${kebab}.controller.spec.ts`);
console.log('');
console.log(`Next steps:`);
console.log(`  1. Add ${pascal}Module to app.module.ts imports`);
console.log(`  2. Implement ${pascal}Service methods with real Prisma queries`);
console.log(`  3. Add routes to route-inventory.spec.ts`);
