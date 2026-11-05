import { validateConfig, assertProductionConfig, ConfigValidationError } from './config-validation.service';

const VALID_PROD: NodeJS.ProcessEnv = {
  NODE_ENV: 'production',
  DATABASE_URL: 'mysql://user:pass@host:3306/db',
  JWT_SECRET: 'a-very-long-secret-that-is-at-least-32-chars!!',
  STRIPE_SECRET_KEY: 'sk_test_abc123',
  STRIPE_WEBHOOK_SECRET: 'whsec_xyz',
  AWS_S3_BUCKET: 'my-bucket',
  AWS_REGION: 'eu-central-1',
  AWS_ACCESS_KEY_ID: 'AKIAIOSFODNN7EXAMPLE',
  AWS_SECRET_ACCESS_KEY: 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
  CORS_ORIGINS: 'https://app.arteuction.com',
};

describe('validateConfig', () => {
  it('CV1 — no errors with all valid production env vars', () => {
    const { errors } = validateConfig(VALID_PROD);
    expect(errors).toHaveLength(0);
  });

  it('CV2 — error when DATABASE_URL is missing in production', () => {
    const { errors } = validateConfig({ ...VALID_PROD, DATABASE_URL: undefined });
    expect(errors.some(e => e.includes('DATABASE_URL'))).toBe(true);
  });

  it('CV3 — error when JWT_SECRET is too short in production', () => {
    const { errors } = validateConfig({ ...VALID_PROD, JWT_SECRET: 'tooshort' });
    expect(errors.some(e => e.includes('JWT_SECRET'))).toBe(true);
  });

  it('CV4 — error when JWT_SECRET is a placeholder in production', () => {
    const { errors } = validateConfig({ ...VALID_PROD, JWT_SECRET: 'change-me-32-chars-minimum' });
    expect(errors.some(e => e.includes('JWT_SECRET'))).toBe(true);
  });

  it('CV5 — error when STRIPE_SECRET_KEY is missing in production', () => {
    const { errors } = validateConfig({ ...VALID_PROD, STRIPE_SECRET_KEY: undefined });
    expect(errors.some(e => e.includes('STRIPE_SECRET_KEY'))).toBe(true);
  });

  it('CV6 — error when STRIPE_WEBHOOK_SECRET has wrong prefix in production', () => {
    const { errors } = validateConfig({ ...VALID_PROD, STRIPE_WEBHOOK_SECRET: 'wrong_prefix' });
    expect(errors.some(e => e.includes('STRIPE_WEBHOOK_SECRET'))).toBe(true);
  });

  it('CV7 — only warnings (no errors) in development when vars are missing', () => {
    const { errors, warnings } = validateConfig({ NODE_ENV: 'development' });
    expect(errors).toHaveLength(0);
    expect(warnings.length).toBeGreaterThan(0);
  });

  it('CV8 — error when AWS_S3_BUCKET is missing in production', () => {
    const { errors } = validateConfig({ ...VALID_PROD, AWS_S3_BUCKET: undefined });
    expect(errors.some(e => e.includes('AWS_S3_BUCKET'))).toBe(true);
  });

  it('CV9 — error when DATABASE_URL uses wrong protocol', () => {
    const { errors } = validateConfig({ ...VALID_PROD, DATABASE_URL: 'postgres://user:pass@host/db' });
    expect(errors.some(e => e.includes('DATABASE_URL'))).toBe(true);
  });

  it('CV10 — warning (not error) when MAIL_HOST missing in production', () => {
    const { errors, warnings } = validateConfig({ ...VALID_PROD, MAIL_HOST: undefined });
    expect(errors.filter(e => e.includes('MAIL_HOST'))).toHaveLength(0);
    expect(warnings.some(w => w.includes('MAIL_HOST'))).toBe(true);
  });
});

describe('assertProductionConfig', () => {
  it('CV11 — does not throw when all required vars present', () => {
    expect(() => assertProductionConfig(VALID_PROD)).not.toThrow();
  });

  it('CV12 — throws ConfigValidationError in production with missing vars', () => {
    expect(() => assertProductionConfig({ ...VALID_PROD, JWT_SECRET: undefined }))
      .toThrow(ConfigValidationError);
  });

  it('CV13 — thrown error contains the failing key name', () => {
    let caught: unknown;
    try {
      assertProductionConfig({ ...VALID_PROD, STRIPE_SECRET_KEY: undefined });
    } catch (err) {
      caught = err;
    }
    expect(caught).toBeInstanceOf(ConfigValidationError);
    expect((caught as ConfigValidationError).message).toContain('STRIPE_SECRET_KEY');
  });

  it('CV14 — does not throw in development even with missing vars', () => {
    expect(() => assertProductionConfig({ NODE_ENV: 'development' })).not.toThrow();
  });

  it('CV15 — error when CORS_ORIGINS is empty string in production', () => {
    const { errors } = validateConfig({ ...VALID_PROD, CORS_ORIGINS: '' });
    expect(errors.some(e => e.includes('CORS_ORIGINS'))).toBe(true);
  });

  it('CV16 — warning (not error) when APP_URL is missing in production', () => {
    const { errors, warnings } = validateConfig({ ...VALID_PROD, APP_URL: undefined });
    expect(errors.filter(e => e.includes('APP_URL'))).toHaveLength(0);
    expect(warnings.some(w => w.includes('APP_URL'))).toBe(true);
  });
});
