import { NestFactory } from '@nestjs/core';
import {
  FastifyAdapter,
  NestFastifyApplication,
} from '@nestjs/platform-fastify';
import fastifyCookie from '@fastify/cookie';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import { AppModule } from './app.module';
import { assertProductionConfig } from './config-validation.service';

async function bootstrap() {
  // Validate config before allocating any resources — throws on failure so
  // logs flush and the process exits cleanly with a non-zero code.
  assertProductionConfig();

  const app = await NestFactory.create<NestFastifyApplication>(
    AppModule,
    new FastifyAdapter({ logger: true }),
  );

  app.enableShutdownHooks();

  // Register cookie plugin so auth controller can set/clear HTTP-only cookies
  await app.register(fastifyCookie);

  app.setGlobalPrefix('api/v1');

  const allowedOrigins = process.env.CORS_ORIGINS
    ? process.env.CORS_ORIGINS.split(',').map(o => o.trim())
    : ['http://localhost:5173', 'http://localhost:5174'];

  app.enableCors({
    origin: allowedOrigins,
    methods: ['GET', 'HEAD', 'PUT', 'PATCH', 'POST', 'DELETE', 'OPTIONS'],
    allowedHeaders: ['Content-Type', 'Authorization'],
    credentials: true,
  });

  const openApiConfig = new DocumentBuilder()
    .setTitle('ARTeuCtion API')
    .setDescription('NestJS backend — proof of concept')
    .setVersion('1.0')
    .addBearerAuth()
    .build();
  const document = SwaggerModule.createDocument(app, openApiConfig);
  SwaggerModule.setup('api/docs', app, document);

  await app.listen(process.env.PORT ?? 3000, '0.0.0.0');
  console.log(`ARTeuCtion API running on port ${process.env.PORT ?? 3000}`);
}

bootstrap();
