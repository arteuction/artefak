import { Injectable, OnModuleInit, OnModuleDestroy, Logger } from '@nestjs/common';
import { PrismaClient } from '@prisma/client';
import { PrismaMariaDb } from '@prisma/adapter-mariadb';

// Prisma 7 requires an explicit driver adapter. The native MariaDB adapter
// is used here so the connection URL stays in the environment variable,
// not hardcoded in the class.
@Injectable()
export class PrismaService extends PrismaClient implements OnModuleInit, OnModuleDestroy {
  private readonly logger = new Logger(PrismaService.name);

  constructor() {
    const adapter = new PrismaMariaDb(process.env.DATABASE_URL!);
    super({ adapter } as any);
  }

  async onModuleInit() {
    await this.$connect();
    this.logger.log('Connected to MariaDB via Prisma 7');
  }

  async onModuleDestroy() {
    await this.$disconnect();
  }
}
