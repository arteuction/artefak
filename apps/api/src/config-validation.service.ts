import { Injectable, Logger, OnApplicationBootstrap } from '@nestjs/common';

interface EnvRule {
  key: string;
  required: boolean;
  description: string;
  validate?: (value: string) => void;
}

const PRODUCTION_RULES: EnvRule[] = [
  // ── Database ──────────────────────────────────────────────────────────────
  {
    key: 'DATABASE_URL',
    required: true,
    description: 'MariaDB connection string',
    validate: v => {
      if (!v.startsWith('mysql://') && !v.startsWith('mysql2://'))
        throw new Error('DATABASE_URL must use mysql:// protocol');
    },
  },

  // ── Auth ──────────────────────────────────────────────────────────────────
  {
    key: 'JWT_SECRET',
    required: true,
    description: 'JWT signing secret',
    validate: v => {
      if (v.length < 32)
        throw new Error('JWT_SECRET must be at least 32 characters');
      if (v === 'change-me-32-chars-minimum' || v === 'changeme' || v === 'secret')
        throw new Error('JWT_SECRET must not use a placeholder value');
    },
  },

  // ── Stripe ────────────────────────────────────────────────────────────────
  {
    key: 'STRIPE_SECRET_KEY',
    required: true,
    description: 'Stripe secret key (sk_live_... or sk_test_...)',
    validate: v => {
      if (!v.startsWith('sk_live_') && !v.startsWith('sk_test_'))
        throw new Error('STRIPE_SECRET_KEY must start with sk_live_ or sk_test_');
    },
  },
  {
    key: 'STRIPE_WEBHOOK_SECRET',
    required: true,
    description: 'Stripe webhook signing secret (whsec_...)',
    validate: v => {
      if (!v.startsWith('whsec_'))
        throw new Error('STRIPE_WEBHOOK_SECRET must start with whsec_');
    },
  },

  // ── S3 ────────────────────────────────────────────────────────────────────
  { key: 'AWS_S3_BUCKET', required: true, description: 'S3 bucket name' },
  { key: 'AWS_REGION', required: true, description: 'AWS/S3 region' },
  { key: 'AWS_ACCESS_KEY_ID', required: true, description: 'S3 access key' },
  { key: 'AWS_SECRET_ACCESS_KEY', required: true, description: 'S3 secret key' },

  // ── CORS ─────────────────────────────────────────────────────────────────
  {
    key: 'CORS_ORIGINS',
    required: true,
    description: 'Allowed CORS origins (comma-separated)',
    validate: v => {
      if (!v.trim())
        throw new Error('CORS_ORIGINS must not be empty');
    },
  },
];

/**
 * Returns { errors, warnings } — pure function usable in tests without side-effects.
 */
export function validateConfig(env: NodeJS.ProcessEnv = process.env): { errors: string[]; warnings: string[] } {
  const nodeEnv = env.NODE_ENV ?? 'development';
  const isProduction = nodeEnv === 'production';

  const errors: string[] = [];
  const warnings: string[] = [];

  for (const rule of PRODUCTION_RULES) {
    const value = env[rule.key] ?? '';

    if (!value) {
      const msg = `${rule.key} is not set (${rule.description})`;
      if (rule.required && isProduction) errors.push(msg);
      else warnings.push(msg);
      continue;
    }

    if (rule.validate) {
      try {
        rule.validate(value);
      } catch (err) {
        const msg = `${rule.key}: ${(err as Error).message}`;
        if (isProduction) errors.push(msg);
        else warnings.push(msg);
      }
    }
  }

  if (isProduction) {
    if (env.STRIPE_SECRET_KEY?.startsWith('sk_test_'))
      warnings.push('STRIPE_SECRET_KEY is a test key — ensure this is intentional for production');
    if (!env.MAIL_HOST)
      warnings.push('MAIL_HOST is not set — email notifications will be silently dropped');
    if (!env.MEILISEARCH_HOST)
      warnings.push('MEILISEARCH_HOST is not set — search will fall back to SQL LIKE queries');
  }

  return { errors, warnings };
}

/**
 * Runs at startup and aborts the process when required env vars are missing
 * or obviously misconfigured in production.
 *
 * In non-production environments it warns rather than throws.
 */
@Injectable()
export class ConfigValidationService implements OnApplicationBootstrap {
  private readonly logger = new Logger(ConfigValidationService.name);

  onApplicationBootstrap(): void {
    const { errors, warnings } = validateConfig(process.env);

    for (const w of warnings) {
      this.logger.warn(`[config] ${w}`);
    }

    if (errors.length > 0) {
      this.logger.error('=== FATAL: production configuration is incomplete ===');
      for (const e of errors) {
        this.logger.error(`  ✗ ${e}`);
      }
      this.logger.error('Set all required environment variables before starting in production.');
      process.exit(1);
    }

    if ((process.env.NODE_ENV ?? 'development') === 'production') {
      this.logger.log('[config] All required environment variables validated ✓');
    }
  }
}
