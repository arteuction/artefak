import {
  Injectable,
  UnauthorizedException,
  ConflictException,
  ForbiddenException,
  BadRequestException,
  NotFoundException,
} from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import * as bcrypt from 'bcrypt';
import * as crypto from 'crypto';
import { PrismaService } from '../prisma.service';
import { EmailService } from '../email/email.service';
import { AuthenticatedUser, JwtPayload, TokenPair, UserRole } from './auth.types';

const ACCESS_TOKEN_TTL  = 15 * 60;         // 15 minutes
const REFRESH_TOKEN_TTL = 30 * 24 * 3600;  // 30 days

@Injectable()
export class AuthService {
  constructor(
    private readonly prisma: PrismaService,
    private readonly jwt: JwtService,
    private readonly email: EmailService,
  ) {}

  // â”€â”€ Credential validation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  async validateCredentials(email: string, password: string): Promise<AuthenticatedUser> {
    const user = await this.prisma.user.findUnique({
      where: { email },
      select: { id: true, email: true, role: true, password: true, email_verified_at: true },
    });

    if (!user) {
      // Constant-time comparison to prevent user-enumeration via timing
      await bcrypt.compare(password, '$2b$12$invalidhashplaceholderXXXXXXXXXXXXXXXXXXXXXXXXXX');
      throw new UnauthorizedException('Invalid credentials');
    }

    const valid = await bcrypt.compare(password, user.password);
    if (!valid) {
      throw new UnauthorizedException('Invalid credentials');
    }

    return {
      id: Number(user.id),
      email: user.email,
      role: user.role as UserRole,
      emailVerifiedAt: user.email_verified_at,
    };
  }

  // â”€â”€ Token issuance â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  async issueTokenPair(user: AuthenticatedUser): Promise<TokenPair> {
    const payload: JwtPayload = { sub: user.id, email: user.email, role: user.role };

    const accessToken = this.jwt.sign(payload, {
      expiresIn: ACCESS_TOKEN_TTL,
      secret: process.env.JWT_SECRET ?? 'change-me-in-production',
    });

    // Opaque random token — always unique, never deterministic
    const refreshToken = crypto.randomBytes(48).toString('hex');
    const refreshHash  = crypto.createHash('sha256').update(refreshToken).digest('hex');
    const expiresAt    = new Date(Date.now() + REFRESH_TOKEN_TTL * 1000);

    await this.prisma.$executeRaw`
      INSERT INTO refresh_tokens (user_id, token_hash, expires_at)
      VALUES (${user.id}, ${refreshHash}, ${expiresAt})
    `;

    return { accessToken, refreshToken };
  }

  // â”€â”€ Refresh token rotation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  async rotateRefreshToken(userId: number, incomingRefreshToken: string): Promise<TokenPair> {
    const tokenHash = crypto.createHash('sha256').update(incomingRefreshToken).digest('hex');

    const [row] = await this.prisma.$queryRaw<
      Array<{ id: number; user_id: number; revoked_at: Date | null; expires_at: Date }>
    >`
      SELECT id, user_id, revoked_at, expires_at
      FROM refresh_tokens
      WHERE token_hash = ${tokenHash}
      LIMIT 1
    `;

    if (!row) {
      throw new UnauthorizedException('Refresh token not found');
    }

    if (row.revoked_at !== null) {
      // Token reuse detected â€” revoke all tokens for this user (theft indicator)
      await this.revokeAllForUser(userId);
      throw new ForbiddenException('Refresh token reuse detected');
    }

    if (new Date(row.expires_at) < new Date()) {
      throw new UnauthorizedException('Refresh token expired');
    }

    // Revoke the used token immediately (rotation)
    await this.prisma.$executeRaw`
      UPDATE refresh_tokens SET revoked_at = UTC_TIMESTAMP() WHERE id = ${row.id}
    `;

    const user = await this.prisma.user.findUnique({
      where: { id: userId },
      select: { id: true, email: true, role: true, email_verified_at: true },
    });

    if (!user) {
      throw new UnauthorizedException('User not found');
    }

    const authenticated: AuthenticatedUser = {
      id: Number(user.id),
      email: user.email,
      role: user.role as UserRole,
      emailVerifiedAt: user.email_verified_at,
    };

    return this.issueTokenPair(authenticated);
  }

  // â”€â”€ Logout â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  async revokeToken(incomingRefreshToken: string): Promise<void> {
    const tokenHash = crypto.createHash('sha256').update(incomingRefreshToken).digest('hex');
    await this.prisma.$executeRaw`
      UPDATE refresh_tokens
      SET revoked_at = UTC_TIMESTAMP()
      WHERE token_hash = ${tokenHash} AND revoked_at IS NULL
    `;
  }

  async revokeAllForUser(userId: number): Promise<void> {
    await this.prisma.$executeRaw`
      UPDATE refresh_tokens
      SET revoked_at = UTC_TIMESTAMP()
      WHERE user_id = ${userId} AND revoked_at IS NULL
    `;
  }

  // â”€â”€ Registration â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€

  async register(
    name: string,
    email: string,
    password: string,
  ): Promise<AuthenticatedUser> {
    const existing = await this.prisma.user.findUnique({ where: { email } });
    if (existing) {
      throw new ConflictException('Email already registered');
    }

    const hash = await bcrypt.hash(password, 12);

    await this.prisma.$executeRaw`
      INSERT INTO users (name, email, password, role, created_at, updated_at)
      VALUES (${name}, ${email}, ${hash}, 'buyer', UTC_TIMESTAMP(), UTC_TIMESTAMP())
    `;

    const user = await this.prisma.user.findUnique({
      where: { email },
      select: { id: true, email: true, role: true, email_verified_at: true },
    });

    return {
      id: Number(user!.id),
      email: user!.email,
      role: user!.role as UserRole,
      emailVerifiedAt: user!.email_verified_at,
    };
  }

  // ── Email verification ────────────────────────────────────────────────────────

  async sendVerificationEmail(userId: number): Promise<{ token: string }> {
    const user = await this.prisma.user.findUnique({
      where: { id: BigInt(userId) },
      select: { id: true, email: true, email_verified_at: true },
    });
    if (!user) throw new NotFoundException('User not found');
    if (user.email_verified_at) throw new BadRequestException('Email already verified');

    const token = crypto.randomBytes(32).toString('hex');
    const expiresAt = new Date(Date.now() + 24 * 60 * 60 * 1000); // 24h

    await this.prisma.$executeRaw`
      INSERT INTO email_verification_tokens (user_id, token, expires_at, created_at)
      VALUES (${userId}, ${token}, ${expiresAt}, UTC_TIMESTAMP())
      ON DUPLICATE KEY UPDATE token = ${token}, expires_at = ${expiresAt}, created_at = UTC_TIMESTAMP()
    `;

    // Fire-and-forget: email delivery failure must not block the response
    void Promise.resolve(this.email.sendEmailVerification(user.email, token)).catch(() => void 0);

    return { token };
  }

  async verifyEmail(token: string): Promise<{ verified: boolean }> {
    const rows = await this.prisma.$queryRaw<Array<{ user_id: number; expires_at: Date }>>`
      SELECT user_id, expires_at
      FROM email_verification_tokens
      WHERE token = ${token}
      LIMIT 1
    `;

    const row = rows[0];
    if (!row) throw new BadRequestException('Invalid or expired verification token');
    if (new Date() > row.expires_at) throw new BadRequestException('Verification token has expired');

    await this.prisma.$executeRaw`
      UPDATE users SET email_verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
      WHERE id = ${row.user_id}
    `;
    await this.prisma.$executeRaw`
      DELETE FROM email_verification_tokens WHERE user_id = ${row.user_id}
    `;

    return { verified: true };
  }

  // ── Auth audit log ────────────────────────────────────────────────────────────

  async logAuthEvent(
    userId: number,
    action: string,
    ipAddress?: string,
  ): Promise<void> {
    // admin_audit_log schema: actor_id, subject_type, subject_id, action, payload
    try {
      const payload = JSON.stringify({ ip: ipAddress ?? 'unknown' });
      await this.prisma.$executeRaw`
        INSERT INTO admin_audit_log (actor_id, subject_type, subject_id, action, payload, created_at)
        VALUES (${userId}, 'User', ${userId}, ${action}, ${payload}, UTC_TIMESTAMP())
      `;
    } catch {
      // Audit log failure must not block authentication
    }
  }
}

