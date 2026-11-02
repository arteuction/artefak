/**
 * Phase 0.3V — AuthController unit tests (direct controller method calls)
 *
 * AC1  register — missing fields: throws BadRequestException
 * AC2  register — short password: throws BadRequestException
 * AC3  register — valid: returns user + access_token
 * AC4  login — missing fields: throws BadRequestException
 * AC5  login — valid: returns user + access_token
 * AC6  logout — revokes token and clears cookie
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { BadRequestException } from '@nestjs/common';
import { AuthController } from './auth.controller';
import { AuthService } from './auth.service';

const mockAuthService = {
  register: jest.fn() as any,
  validateCredentials: jest.fn() as any,
  issueTokenPair: jest.fn() as any,
  logAuthEvent: jest.fn() as any,
  revokeToken: jest.fn() as any,
  revokeAllForUser: jest.fn() as any,
};

// Minimal Express-like res/req stubs
const makeRes = () => {
  const cookies: Record<string, string> = {};
  return {
    cookie: jest.fn() as any,
    clearCookie: jest.fn() as any,
    _cookies: cookies,
  };
};

const makeReq = (overrides: object = {}) => ({
  ip: '127.0.0.1',
  cookies: {},
  ...overrides,
});

describe('Phase 0.3V — AuthController', () => {
  let ctrl: AuthController;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [AuthController],
      providers: [{ provide: AuthService, useValue: mockAuthService }],
    }).compile();
    ctrl = module.get(AuthController);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('AC1: register — missing fields: throws BadRequestException', async () => {
    const res = makeRes();
    await expect(ctrl.register({}, res as any, makeReq() as any)).rejects.toThrow(BadRequestException);
  });

  it('AC2: register — short password: throws BadRequestException', async () => {
    const res = makeRes();
    await expect(ctrl.register({ name: 'X', email: 'x@x.com', password: 'short' }, res as any, makeReq() as any)).rejects.toThrow(BadRequestException);
  });

  it('AC3: register — valid: returns user + access_token', async () => {
    mockAuthService.register.mockResolvedValueOnce({ id: 1, email: 'test@test.com', role: 'buyer', emailVerifiedAt: null });
    mockAuthService.issueTokenPair.mockResolvedValueOnce({ accessToken: 'tok', refreshToken: 'ref' });
    mockAuthService.logAuthEvent.mockResolvedValueOnce(undefined);

    const res = makeRes();
    const result = await ctrl.register({ name: 'Test', email: 'test@test.com', password: 'password123' }, res as any, makeReq() as any);
    expect(result.access_token).toBe('tok');
    expect(result.user.email).toBe('test@test.com');
    expect(res.cookie).toHaveBeenCalledWith('refresh_token', 'ref', expect.any(Object));
  });

  it('AC4: login — missing fields: throws BadRequestException', async () => {
    const res = makeRes();
    await expect(ctrl.login({}, res as any, makeReq() as any)).rejects.toThrow(BadRequestException);
  });

  it('AC5: login — valid: returns user + access_token', async () => {
    mockAuthService.validateCredentials.mockResolvedValueOnce({ id: 1, email: 'user@test.com', role: 'buyer', emailVerifiedAt: null });
    mockAuthService.issueTokenPair.mockResolvedValueOnce({ accessToken: 'tok', refreshToken: 'ref' });
    mockAuthService.logAuthEvent.mockResolvedValueOnce(undefined);

    const res = makeRes();
    const result = await ctrl.login({ email: 'user@test.com', password: 'password123' }, res as any, makeReq() as any);
    expect(result.access_token).toBe('tok');
    expect(result.user.role).toBe('buyer');
  });

  it('AC6: logout — revokes cookie and calls revokeToken', async () => {
    mockAuthService.revokeToken.mockResolvedValueOnce(undefined);
    mockAuthService.logAuthEvent.mockResolvedValueOnce(undefined);

    const res = makeRes();
    const user = { id: 1, role: 'buyer' as const, email: 'u@u.com', emailVerifiedAt: null };
    await ctrl.logout(user, makeReq({ cookies: { refresh_token: 'old-token' } }) as any, res as any);
    expect(mockAuthService.revokeToken).toHaveBeenCalledWith('old-token');
    expect(res.clearCookie).toHaveBeenCalled();
  });
});
