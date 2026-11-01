import 'dotenv/config';
import { defineConfig } from 'prisma/config';
import { PrismaMariaDb } from '@prisma/adapter-mariadb';

// Prisma 7: connection URL moves from schema.prisma to here.
// @prisma/adapter-mariadb is the native adapter for MariaDB 11.
export default defineConfig({
  earlyAccess: true,
  datasources: {
    db: {
      adapter: new PrismaMariaDb(process.env.DATABASE_URL!),
    },
  },
});
