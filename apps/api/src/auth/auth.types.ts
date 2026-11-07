export type UserRole = 'buyer' | 'artist' | 'admin' | 'operator' | 'curator';

export interface JwtPayload {
  sub: number;
  email: string;
  role: UserRole;
  /** Issued-at (seconds) */
  iat?: number;
  /** Expires-at (seconds) */
  exp?: number;
}

export interface AuthenticatedUser {
  id: number;
  email: string;
  role: UserRole;
  emailVerifiedAt: Date | null;
}

export interface TokenPair {
  accessToken: string;
  refreshToken: string;
}
