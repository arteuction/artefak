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
 * AS11 sendVerificationEmail — already verified: throws BadRequestException
 * AS12 sendVerificationEmail — unverified: returns token
 * AS13 verifyEmail — invalid token: throws BadRequestException
 * AS14 verifyEmail — valid token: returns { verified: true }
 * AS15 sendVerificationEmail — user not found: throws NotFoundException
 * AS16 rotateRefreshToken — user deleted after rotation: throws UnauthorizedException
 * AS17 verifyEmail — expired token: throws BadRequestException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import {
  UnauthorizedException, ConflictException, ForbiddenException, BadRequestException, NotFoundException,
} from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import * as bcrypt from 'bcrypt';
import { AuthService } from './auth.service';
import { PrismaService } from '../prisma.service';
import { EmailService } from '../email/email.service';

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
const mockEmail = { enabled: false, sendEmailVerification: jest.fn(async () => void 0) as any };

describe('Phase 0.3V — AuthService', () => {
  let svc: AuthService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        AuthService,
        { provide: PrismaService, useValue: mockPrisma },
        { provide: JwtService, useValue: mockJwt },
        { provide: EmailService, useValue: mockEmail },
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

  it('AS11: sendVerificationEmail — already verified: throws BadRequestException', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce({ id: BigInt(1), email: 'u@u.com', email_verified_at: new Date() });
    await expect(svc.sendVerificationEmail(1)).rejects.toThrow(BadRequestException);
  });

  it('AS12: sendVerificationEmail — unverified user: returns token', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce({ id: BigInt(1), email: 'u@u.com', email_verified_at: null });
    mockPrisma.$executeRaw.mockResolvedValueOnce(1);
    const result = await svc.sendVerificationEmail(1);
    expect(typeof result.token).toBe('string');
    expect(result.token.length).toBeGreaterThan(10);
  });

  it('AS13: verifyEmail — invalid token: throws BadRequestException', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([]);
    await expect(svc.verifyEmail('bad-token')).rejects.toThrow(BadRequestException);
  });

  it('AS14: verifyEmail — valid token: returns { verified: true }', async () => {
    const future = new Date(Date.now() + 60_000);
    mockPrisma.$queryRaw.mockResolvedValueOnce([{ user_id: 1, expires_at: future }]);
    mockPrisma.$executeRaw.mockResolvedValue(1);
    const result = await svc.verifyEmail('valid-token');
    expect(result.verified).toBe(true);
  });

  it('AS15: sendVerificationEmail — user not found: throws NotFoundException', async () => {
    mockPrisma.user.findUnique.mockResolvedValueOnce(null);
    await expect(svc.sendVerificationEmail(999)).rejects.toThrow(NotFoundException);
  });

  it('AS16: rotateRefreshToken — user deleted after token rotation: throws UnauthorizedException', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{
      id: 1, user_id: 1, revoked_at: null, expires_at: new Date(Date.now() + 60_000),
    }]);
    mockPrisma.$executeRaw.mockResolvedValueOnce(1); // revoke used token
    mockPrisma.user.findUnique.mockResolvedValueOnce(null); // user gone
    await expect(svc.rotateRefreshToken(1, 'valid-token')).rejects.toThrow(UnauthorizedException);
  });

  it('AS17: verifyEmail — expired token: throws BadRequestException', async () => {
    mockPrisma.$queryRaw.mockResolvedValueOnce([{ user_id: 1, expires_at: new Date(Date.now() - 60_000) }]);
    await expect(svc.verifyEmail('expired-token')).rejects.toThrow(BadRequestException);
  });
});
