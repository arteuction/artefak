#!/usr/bin/env ts-node
/**
 * OpenAPI export
 *
 * Boots the NestJS app, generates the OpenAPI document via SwaggerModule,
 * and writes it to openapi.json (project root by default, or --out <path>).
 *
 * Usage: npm run export:openapi [-- --out ./openapi.json]
 *
 * The generated file can then be fed to openapi-typescript:
 *   npx openapi-typescript openapi.json -o resources/frontend/src/lib/api-types.gen.ts
 */

import { NestFactory } from '@nestjs/core';
import { NestFastifyApplication, FastifyAdapter } from '@nestjs/platform-fastify';
import { SwaggerModule, DocumentBuilder } from '@nestjs/swagger';
import { writeFileSync } from 'fs';
import { resolve } from 'path';

const flags = process.argv.slice(2);
const outIdx = flags.indexOf('--out');
const outPath = outIdx !== -1 ? resolve(flags[outIdx + 1]) : resolve(join(import.meta.dirname ?? __dirname, '..', 'openapi.json'));

// Lazy import to avoid circular resolution at module load time
const { AppModule } = await import('../src/app.module.js');

function join(...parts: string[]) {
  return parts.join('/').replace(/\/+/g, '/');
}

const app = await NestFactory.create<NestFastifyApplication>(
  AppModule,
  new FastifyAdapter(),
  { logger: ['error'] },
);

app.setGlobalPrefix('api/v1');

const config = new DocumentBuilder()
  .setTitle('ARTeuCtion API')
  .setDescription('NestJS PoC — route contract')
  .setVersion('1.0')
  .addBearerAuth()
  .build();

const document = SwaggerModule.createDocument(app, config);
writeFileSync(outPath, JSON.stringify(document, null, 2));

await app.close();
console.log(`OpenAPI spec written to: ${outPath}`);
console.log(`\nTo generate TypeScript types for the frontend:`);
console.log(`  npx openapi-typescript ${outPath} -o resources/frontend/src/lib/api-types.gen.ts`);
