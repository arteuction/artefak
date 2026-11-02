/**
 * Phase 0.3V — AuthService unit tests
 *
 * AS1  validateCredentials — user not found: throws UnauthorizedException
 * AS2  validateCredentials — wrong password: throws UnauthorizedException
 * AS3  validateCredentials — correct credentials: returns AuthenticatedUser
 * AS4  register — email exists: throws ConflictException
 * AS5  register — new email: creates user and returns AuthenticatedUser
 * AS6  rotateRefreshToken — token not found: throws UnauthorizedException
 * AS7  rotateRefreshToken — token revoked: throws ForbiddenException
 * AS8  rotateRefreshToken — token expired: throws UnauthorizedException
 * AS9  revokeToken — calls $executeRaw
 * AS10 revokeAllForUser — calls $executeRaw
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import {
  UnauthorizedException, ConflictException, ForbiddenException,
} from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import * as bcrypt from 'bcrypt';
import { AuthService } from './auth.service';
import { PrismaService } from '../prisma.service';

const HASH = await bcrypt.hash('password123', 12);

const STUB_USER = {
  id: BigInt(1), email: 'user@test.com', role: 'buyer',
  password: HASH, email_verified_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  user: { findUnique: fn() },
  $executeRaw: fn(),
  $queryRaw: fn(),
};

const mockJwt = { sign: jest.fn() as any };

describe('Phase 0.3V — AuthService', () => {
  let svc: AuthService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        AuthService,
        { provide: PrismaService, useValue: mockPrisma },
        { provide: JwtService, useValue: mockJwt },
      ],
    }).compile();
    svc = module.get(AuthService);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('AS1: validateCredentials — user not found: throws UnauthorizedException', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce(null);
    await expect(svc.validateCredentials('missing@test.com', 'pass')).rejects.toThrow(UnauthorizedException);
  });

  it('AS2: validateCredentials — wrong password: throws UnauthorizedException', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce(STUB_USER);
    await expect(svc.validateCredentials('user@test.com', 'wrongpass')).rejects.toThrow(UnauthorizedException);
  });

  it('AS3: validateCredentials — correct credentials: returns user', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce(STUB_USER);
    const result = await svc.validateCredentials('user@test.com', 'password123');
    expect(result.id).toBe(1);
    expect(result.email).toBe('user@test.com');
    expect(result.role).toBe('buyer');
  });

  it('AS4: register — email exists: throws ConflictException', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce(STUB_USER);
    await expect(svc.register('Test', 'user@test.com', 'password123')).rejects.toThrow(ConflictException);
  });

  it('AS5: register — new email: creates user and returns it', async () => {
    mockPrisma.user.findUnique
      .mockResolvedValueOnce(null)   // check existing
      .mockResolvedValueOnce(STUB_USER); // after insert
    mockPrisma.$executeRaw.mockResolvedValueOnce(1);
    const result = await svc.register('Test', 'user@test.com', 'password123');
    expect(result.email).toBe('user@test.com');
  });

  it('AS6: rotateRefreshToken — token not found: throws UnauthorizedException', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([]);
    await expect(svc.rotateRefreshToken(1, 'bad-token')).rejects.toThrow(UnauthorizedException);
  });

  it('AS7: rotateRefreshToken — token revoked: throws ForbiddenException', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{
      id: 1, user_id: 1, revoked_at: new Date(), expires_at: new Date(Date.now() + 10000),
    }]);
    mockPrisma.$executeRaw.mockResolvedValueOnce(1); // revokeAll
    await expect(svc.rotateRefreshToken(1, 'revoked-token')).rejects.toThrow(ForbiddenException);
  });

  it('AS8: rotateRefreshToken — token expired: throws UnauthorizedException', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{
      id: 1, user_id: 1, revoked_at: null, expires_at: new Date(Date.now() - 10000),
    }]);
    await expect(svc.rotateRefreshToken(1, 'expired-token')).rejects.toThrow(UnauthorizedException);
  });

  it('AS9: revokeToken — calls $executeRaw', async () => {
    mockPrisma.$executeRaw.mockResolvedValueOnce(1);
    await svc.revokeToken('some-refresh-token');
    expect(mockPrisma.$executeRaw).toHaveBeenCalled();
  });

  it('AS10: revokeAllForUser — calls $executeRaw', async () => {
    mockPrisma.$executeRaw.mockResolvedValueOnce(1);
    await svc.revokeAllForUser(1);
    expect(mockPrisma.$executeRaw).toHaveBeenCalled();
  });
});
