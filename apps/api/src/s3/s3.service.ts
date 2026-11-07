import { Injectable, Logger } from '@nestjs/common';
import { S3Client, PutObjectCommand, GetObjectCommand } from '@aws-sdk/client-s3';
import { getSignedUrl } from '@aws-sdk/s3-request-presigner';

@Injectable()
export class S3Service {
  private readonly logger = new Logger(S3Service.name);
  private readonly client: S3Client;
  readonly bucket: string;
  readonly enabled: boolean;

  constructor() {
    this.bucket = process.env.AWS_S3_BUCKET ?? '';
    this.enabled = !!(
      process.env.AWS_REGION &&
      process.env.AWS_S3_BUCKET &&
      (process.env.AWS_ACCESS_KEY_ID || process.env.AWS_ROLE_ARN)
    );

    this.client = new S3Client({
      region: process.env.AWS_REGION ?? 'eu-central-1',
      ...(process.env.AWS_ENDPOINT_URL ? { endpoint: process.env.AWS_ENDPOINT_URL } : {}),
    });
  }

  async presignUpload(key: string, contentType: string, expiresIn = 3600): Promise<string> {
    const cmd = new PutObjectCommand({ Bucket: this.bucket, Key: key, ContentType: contentType });
    return getSignedUrl(this.client, cmd, { expiresIn });
  }

  async presignDownload(key: string, expiresIn = 3600): Promise<string> {
    const cmd = new GetObjectCommand({ Bucket: this.bucket, Key: key });
    return getSignedUrl(this.client, cmd, { expiresIn });
  }

  /** Stub URL used when S3 credentials are not configured (dev / test). */
  stubUploadUrl(key: string): string {
    return `https://stub-s3.local/presign?key=${encodeURIComponent(key)}`;
  }

  stubDownloadUrl(key: string): string {
    return `https://stub-s3.local/download?key=${encodeURIComponent(key)}`;
  }
}
