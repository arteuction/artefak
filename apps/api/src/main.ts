import { NestFactory } from '@nestjs/core';
import {
  FastifyAdapter,
  NestFastifyApplication,
} from '@nestjs/platform-fastify';
import fastifyCookie from '@fastify/cookie';
import { DocumentBuilder, SwaggerModule } from '@nestjs/swagger';
import { AppModule } from './app.module';

async function bootstrap() {
  const app = await NestFactory.create<NestFastifyApplication>(
    AppModule,
    new FastifyAdapter({ logger: true }),
  );

  // Register cookie plugin so auth controller can set/clear HTTP-only cookies
  await app.register(fastifyCookie);

  app.setGlobalPrefix('api/v1');

  app.enableCors({
    origin: [
      'http://localhost:5173', // legacy marketplace (Laravel Vite)
      'http://localhost:5174', // standalone frontend
    ],
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
