/**
 * Phase 0.3U â€” GovernanceService unit tests
 *
 * GV1   requestErasure â€” always returns scheduled status
 * GV2   exportData     â€” always returns queued status
 * GV3   myConsents     â€” delegates to prisma.user_consents.findMany
 * GV4   listPolicies   â€” delegates to prisma.governance_policies.findMany
 * GV5   giveConsent    â€” policy not found: throws NotFoundException
 * GV6   giveConsent    â€” found: upserts and returns consent row
 * GV7   withdrawConsent â€” consent not found: throws NotFoundException
 * GV8   withdrawConsent â€” found: updates withdrawn_at
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { GovernanceService } from './governance.service';
import { PrismaService } from '../prisma.service';

const fn = () => jest.fn() as any;
const mockPrisma = {
  governance_policies: {
    findMany: fn(),
    findFirst: fn(),
  },
  user_consents: {
    findMany: fn(),
    findFirst: fn(),
    upsert: fn(),
    update: fn(),
  },
};

describe('Phase 0.3U â€” GovernanceService', () => {
  let svc: GovernanceService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        GovernanceService,
        { provide: PrismaService, useValue: mockPrisma },
      ],
    }).compile();
    svc = module.get(GovernanceService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('GV1: requestErasure returns scheduled status', async () => {
    const result = await svc.requestErasure(1, 'GDPR request');
    expect(result.status).toBe('scheduled');
    expect(result.user_id).toBe(1);
    expect(result).toHaveProperty('message');
  });

  it('GV2: exportData returns queued status', async () => {
    const result = await svc.exportData(1);
    expect(result.status).toBe('queued');
    expect(result.user_id).toBe(1);
  });

  it('GV3: myConsents delegates to prisma', async () => {
    const STUB = [{ id: BigInt(1), governance_policy_id: BigInt(10), consented_at: new Date(), withdrawn_at: null }];
    mockPrisma.user_consents.findMany.mockResolvedValueOnce(STUB);
    const result = await svc.myConsents(1);
    expect(result).toEqual(STUB);
    expect(mockPrisma.user_consents.findMany).toHaveBeenCalledWith(
      expect.objectContaining({ where: { user_id: BigInt(1) } }),
    );
  });

  it('GV4: listPolicies returns active policies', async () => {
    const STUB = [{ id: BigInt(1), type: 'privacy', version: '1.0', effective_from: new Date(), content_url: 'https://example.com/privacy.pdf', created_at: new Date() }];
    mockPrisma.governance_policies.findMany.mockResolvedValueOnce(STUB);
    const result = await svc.listPolicies();
    expect(result).toEqual(STUB);
    expect(mockPrisma.governance_policies.findMany).toHaveBeenCalledWith(
      expect.objectContaining({ where: { is_active: true } }),
    );
  });

  it('GV5: giveConsent â€” policy not found throws NotFoundException', async () => {
    mockPrisma.governance_policies.findFirst.mockResolvedValueOnce(null);
    await expect(svc.giveConsent(1, 999)).rejects.toThrow(NotFoundException);
  });

  it('GV6: giveConsent â€” found policy: upserts and returns consent', async () => {
    mockPrisma.governance_policies.findFirst.mockResolvedValueOnce({ id: BigInt(10) });
    const CONSENT = { id: BigInt(1), governance_policy_id: BigInt(10), consented_at: new Date() };
    mockPrisma.user_consents.upsert.mockResolvedValueOnce(CONSENT);

    const result = await svc.giveConsent(1, 10, '127.0.0.1', 'test-agent');
    expect(result).toEqual(CONSENT);
    expect(mockPrisma.user_consents.upsert).toHaveBeenCalled();
  });

  it('GV7: withdrawConsent â€” consent not found throws NotFoundException', async () => {
    mockPrisma.user_consents.findFirst.mockResolvedValueOnce(null);
    await expect(svc.withdrawConsent(1, 999)).rejects.toThrow(NotFoundException);
  });

  it('GV8: withdrawConsent â€” found consent: updates withdrawn_at', async () => {
    mockPrisma.user_consents.findFirst.mockResolvedValueOnce({ id: BigInt(1) });
    const UPDATED = { id: BigInt(1), governance_policy_id: BigInt(10), withdrawn_at: new Date() };
    mockPrisma.user_consents.update.mockResolvedValueOnce(UPDATED);

    const result = await svc.withdrawConsent(1, 10);
    expect(result).toEqual(UPDATED);
    expect(mockPrisma.user_consents.update).toHaveBeenCalledWith(
      expect.objectContaining({ data: expect.objectContaining({ withdrawn_at: expect.any(Date) }) }),
    );
  });
});
