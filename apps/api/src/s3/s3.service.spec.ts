/**
 * Phase 0.6B — S3Service unit tests
 *
 * S31  disabled (no credentials): stubUploadUrl returns stub URL with encoded key
 * S32  disabled: stubDownloadUrl returns stub URL with encoded key
 * S33  enabled flag is false when env vars not set
 * S34  enabled flag is true when AWS_REGION + AWS_S3_BUCKET + AWS_ACCESS_KEY_ID all set
 * S35  enabled flag is true when AWS_ROLE_ARN is used instead of access key
 * S36  bucket name comes from AWS_S3_BUCKET env var
 */

import { S3Service } from './s3.service';

describe('Phase 0.6B — S3Service', () => {
  describe('stub URL methods', () => {
    let svc: S3Service;

    beforeAll(() => {
      delete process.env.AWS_REGION;
      delete process.env.AWS_S3_BUCKET;
      delete process.env.AWS_ACCESS_KEY_ID;
      delete process.env.AWS_ROLE_ARN;
      svc = new S3Service();
    });

    it('S31: stubUploadUrl encodes key in URL', () => {
      const url = svc.stubUploadUrl('artworks/test image.jpg');
      expect(url).toContain('stub-s3.local');
      expect(url).toContain(encodeURIComponent('artworks/test image.jpg'));
    });

    it('S32: stubDownloadUrl encodes key in URL', () => {
      const url = svc.stubDownloadUrl('quarantine/1/file.png');
      expect(url).toContain('stub-s3.local');
      expect(url).toContain(encodeURIComponent('quarantine/1/file.png'));
    });

    it('S33: enabled is false when env vars not set', () => {
      expect(svc.enabled).toBe(false);
    });
  });

  describe('enabled flag', () => {
    afterEach(() => {
      delete process.env.AWS_REGION;
      delete process.env.AWS_S3_BUCKET;
      delete process.env.AWS_ACCESS_KEY_ID;
      delete process.env.AWS_ROLE_ARN;
    });

    it('S34: enabled is true when AWS_REGION + AWS_S3_BUCKET + AWS_ACCESS_KEY_ID all set', () => {
      process.env.AWS_REGION = 'eu-central-1';
      process.env.AWS_S3_BUCKET = 'arteuction-test';
      process.env.AWS_ACCESS_KEY_ID = 'AKIAIOSFODNN7EXAMPLE';
      expect(new S3Service().enabled).toBe(true);
    });

    it('S35: enabled is true when AWS_ROLE_ARN is used instead of access key', () => {
      process.env.AWS_REGION = 'eu-central-1';
      process.env.AWS_S3_BUCKET = 'arteuction-test';
      process.env.AWS_ROLE_ARN = 'arn:aws:iam::123456789012:role/test-role';
      expect(new S3Service().enabled).toBe(true);
    });

    it('S36: bucket name comes from AWS_S3_BUCKET env var', () => {
      process.env.AWS_REGION = 'eu-central-1';
      process.env.AWS_S3_BUCKET = 'my-art-bucket';
      process.env.AWS_ACCESS_KEY_ID = 'AKIAIOSFODNN7EXAMPLE';
      expect(new S3Service().bucket).toBe('my-art-bucket');
    });
  });
});
