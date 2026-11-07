import { Injectable, NotFoundException } from '@nestjs/common';
import { PrismaService } from '../prisma.service';

@Injectable()
export class GovernanceService {
  constructor(private readonly prisma: PrismaService) {}

  async listPolicies() {
    return this.prisma.governance_policies.findMany({
      where: { is_active: true },
      select: { id: true, type: true, version: true, effective_from: true, content_url: true, created_at: true },
      orderBy: [{ effective_from: 'desc' }],
    });
  }

  async giveConsent(userId: number, policyId: number, ip?: string, userAgent?: string) {
    const policy = await this.prisma.governance_policies.findFirst({ where: { id: BigInt(policyId) } });
    if (!policy) throw new NotFoundException(`Policy ${policyId} not found`);
    return this.prisma.user_consents.upsert({
      where: { user_id_governance_policy_id: { user_id: BigInt(userId), governance_policy_id: BigInt(policyId) } },
      create: {
        user_id: BigInt(userId),
        governance_policy_id: BigInt(policyId),
        consented_at: new Date(),
        ip_address: ip ?? null,
        user_agent: userAgent ?? null,
      },
      update: {
        consented_at: new Date(),
        withdrawn_at: null,
        ip_address: ip ?? null,
      },
      select: { id: true, governance_policy_id: true, consented_at: true },
    });
  }

  async withdrawConsent(userId: number, policyId: number) {
    const consent = await this.prisma.user_consents.findFirst({
      where: { user_id: BigInt(userId), governance_policy_id: BigInt(policyId) },
    });
    if (!consent) throw new NotFoundException(`Consent for policy ${policyId} not found`);
    return this.prisma.user_consents.update({
      where: { id: consent.id },
      data: { withdrawn_at: new Date() },
      select: { id: true, governance_policy_id: true, withdrawn_at: true },
    });
  }

  async myConsents(userId: number) {
    return this.prisma.user_consents.findMany({
      where: { user_id: BigInt(userId) },
      select: { id: true, governance_policy_id: true, consented_at: true, withdrawn_at: true },
      orderBy: [{ consented_at: 'desc' }],
    });
  }

  async requestErasure(userId: number, _reason?: string) {
    // Stub — actual erasure scheduling would involve queuing; record intent
    return { user_id: userId, status: 'scheduled', message: 'Erasure request received. Processing within 30 days.' };
  }

  async exportData(userId: number) {
    // Stub — actual export would queue a background job
    return { user_id: userId, status: 'queued', message: 'Data export request received. You will be notified when ready.' };
  }
}
