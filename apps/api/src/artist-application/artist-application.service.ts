import { Injectable, NotFoundException, ForbiddenException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class ArtistApplicationService {
  constructor(private readonly prisma: PrismaService) {}

  async listApplications(userId: number, role: string, page = 1, perPage = 20) {
    const skip = (page - 1) * perPage;
    const where = role === 'admin' ? {} : { artist_profiles: { user_id: BigInt(userId) } };
    const [data, total] = await this.prisma.$transaction([
      this.prisma.artist_applications.findMany({
        where,
        select: { id: true, status: true, portfolio_url: true, motivation: true, version: true, created_at: true, artist_profile_id: true },
        orderBy: [{ created_at: 'desc' }],
        skip,
        take: perPage,
      }),
      this.prisma.artist_applications.count({ where }),
    ]);
    return { data, meta: { current_page: page, last_page: Math.max(1, Math.ceil(total / perPage)), per_page: perPage, total } };
  }

  async getApplication(appId: number, userId: number, role: string) {
    const app = await this.prisma.artist_applications.findFirst({
      where: { id: BigInt(appId) },
      select: { id: true, status: true, portfolio_url: true, motivation: true, review_note: true, version: true, created_at: true, artist_profile_id: true, artist_profiles: { select: { user_id: true } } },
    });
    if (!app) throw new NotFoundException(`Application ${appId} not found`);
    if (role !== 'admin' && Number(app.artist_profiles?.user_id) !== userId) throw new ForbiddenException();
    return app;
  }

  async createApplication(userId: number, dto: { portfolio_url?: string; motivation?: string }) {
    const profile = await this.prisma.artist_profiles.findFirst({ where: { user_id: BigInt(userId), deleted_at: null } });
    if (!profile) throw new NotFoundException('Artist profile required before submitting application');
    return this.prisma.artist_applications.create({
      data: {
        artist_profile_id: profile.id,
        portfolio_url: dto.portfolio_url ?? null,
        motivation: dto.motivation ?? null,
        status: 'draft',
      },
      select: { id: true, status: true, portfolio_url: true, motivation: true, version: true, created_at: true },
    });
  }

  async reviewApplication(appId: number, reviewerId: number, decision: 'approved' | 'rejected', note?: string) {
    const app = await this.prisma.artist_applications.findFirst({ where: { id: BigInt(appId) } });
    if (!app) throw new NotFoundException(`Application ${appId} not found`);
    return this.prisma.artist_applications.update({
      where: { id: BigInt(appId) },
      data: {
        status: decision,
        reviewed_by: BigInt(reviewerId),
        reviewed_at: new Date(),
        review_note: note ?? null,
      },
      select: { id: true, status: true, review_note: true, reviewed_at: true },
    });
  }
}
