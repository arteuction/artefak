/**
 * Phase 0.6B — RolesGuard unit tests
 *
 * RG1  canActivate — no roles metadata: allows all (returns true)
 * RG2  canActivate — user has required role: allows (returns true)
 * RG3  canActivate — no user on request: throws ForbiddenException
 * RG4  canActivate — user role not in required roles: throws ForbiddenException
 * RG5  canActivate — multiple required roles: passes when user has any one of them
 */

import { jest } from '@jest/globals';
import { ForbiddenException } from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { RolesGuard } from './roles.guard';
import type { AuthenticatedUser } from './auth.types';

function makeContext(user: AuthenticatedUser | undefined) {
  return {
    getHandler: () => ({}),
    getClass: () => ({}),
    switchToHttp: () => ({
      getRequest: () => ({ user }),
    }),
  } as any;
}

describe('Phase 0.6B — RolesGuard', () => {
  let guard: RolesGuard;
  let reflector: Reflector;

  beforeEach(() => {
    reflector = new Reflector();
    guard = new RolesGuard(reflector);
  });

  it('RG1: no roles metadata — allows all requests', () => {
    jest.spyOn(reflector, 'getAllAndOverride').mockReturnValue(undefined as any);
    const ctx = makeContext({ id: 1, email: 'u@test.com', role: 'buyer', emailVerifiedAt: null });
    expect(guard.canActivate(ctx)).toBe(true);
  });

  it('RG2: user has required role — allows', () => {
    jest.spyOn(reflector, 'getAllAndOverride').mockReturnValue(['admin'] as any);
    const ctx = makeContext({ id: 1, email: 'a@test.com', role: 'admin', emailVerifiedAt: null });
    expect(guard.canActivate(ctx)).toBe(true);
  });

  it('RG3: no user on request — throws ForbiddenException', () => {
    jest.spyOn(reflector, 'getAllAndOverride').mockReturnValue(['admin'] as any);
    const ctx = makeContext(undefined);
    expect(() => guard.canActivate(ctx)).toThrow(ForbiddenException);
  });

  it('RG4: user role not in required roles — throws ForbiddenException', () => {
    jest.spyOn(reflector, 'getAllAndOverride').mockReturnValue(['admin', 'operator'] as any);
    const ctx = makeContext({ id: 2, email: 'b@test.com', role: 'buyer', emailVerifiedAt: null });
    expect(() => guard.canActivate(ctx)).toThrow(ForbiddenException);
  });

  it('RG5: multiple required roles — passes when user has any one of them', () => {
    jest.spyOn(reflector, 'getAllAndOverride').mockReturnValue(['admin', 'operator'] as any);
    const ctx = makeContext({ id: 3, email: 'o@test.com', role: 'operator', emailVerifiedAt: null });
    expect(guard.canActivate(ctx)).toBe(true);
  });
});
