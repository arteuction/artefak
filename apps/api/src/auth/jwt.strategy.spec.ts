/**
 * Phase 0.6B — JwtStrategy unit tests
 *
 * JS1  validate — user found: returns AuthenticatedUser shape
 * JS2  validate — user not found: throws UnauthorizedException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { UnauthorizedException } from '@nestjs/common';
import { JwtStrategy } from './jwt.strategy';
import { PrismaService } from '../prisma.service';

const fn = () => jest.fn() as any;

const mockPrisma = {
  user: { findUnique: fn() },
};

describe('Phase 0.6B — JwtStrategy', () => {
  let strategy: JwtStrategy;

  beforeAll(async () => {
    process.env.JWT_SECRET = 'test-jwt-strategy-secret';
    const module: TestingModule = await Test.createTestingModule({
      providers: [JwtStrategy, { provide: PrismaService, useValue: mockPrisma }],
    }).compile();
    strategy = module.get(JwtStrategy);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('JS1: validate — user found: returns AuthenticatedUser', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce({
      id: BigInt(1), email: 'test@example.com', role: 'buyer', email_verified_at: null,
    });

    const result = await strategy.validate({ sub: BigInt(1) as any, email: 'test@example.com', role: 'buyer' });

    expect(result).toEqual({ id: 1, email: 'test@example.com', role: 'buyer', emailVerifiedAt: null });
  });

  it('JS2: validate — user not found: throws UnauthorizedException', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce(null);

    await expect(strategy.validate({ sub: BigInt(999) as any, email: 'gone@example.com', role: 'buyer' }))
      .rejects.toThrow(UnauthorizedException);
  });
});
