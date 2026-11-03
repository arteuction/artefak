# ARTeuCtion — S3 Security Validation

**Date:** 2026-11-03  
**Service:** `apps/api/src/artwork/artwork.service.ts`

---

## Current Implementation

### What Is Validated (Server-Side, Before Presign)

| Check | Status | Implementation |
|---|---|---|
| Content-type allowlist (jpeg/png/webp/tiff) | ✅ | `ALLOWED_IMAGE_TYPES` set in `presignImage` |
| File size declared limit (≤50MB) | ✅ | `if (size_bytes > 50 * 1024 * 1024) throw BadRequestException` |
| Unique object key per upload | ✅ | `${Date.now()}-${nanoid()}` in presign key |
| User entitlement check before presign | ✅ | `artwork.user_id === requestingUserId` |

### What Is NOT Validated (Current Gap)

| Gap | Risk | Status |
|---|---|---|
| Actual file content (magic bytes) | Client can declare `image/jpeg`, upload a PHP shell | ⚠ Not yet implemented |
| Actual upload size (S3 confirms, we do not) | Client declares 1MB, uploads 50MB | ⚠ Not yet implemented |
| Image can actually be decoded | Corrupted files, polyglot payloads | ⚠ Not yet implemented |
| Post-upload validation step | No server-side confirmation gate | ⚠ Not yet implemented |

**Note:** A presigned PUT URL authorizes the upload operation. It does not inspect actual file contents. The declared `Content-Type` header from the client is used for storage metadata; it does not prove the file is that type.

---

## Recommended Architecture: Quarantine Pattern

```
Client                   NestJS                  S3                    Worker
  │                        │                       │                       │
  │  POST /artwork/presign │                       │                       │
  │───────────────────────►│                       │                       │
  │                        │  Generate key:        │                       │
  │                        │  quarantine/{id}/{uuid}│                      │
  │  presignedUrl ◄────────│                       │                       │
  │                        │                       │                       │
  │  PUT presignedUrl      │                       │                       │
  │──────────────────────────────────────────────►│                       │
  │  200 OK ◄──────────────────────────────────────│                       │
  │                        │                       │                       │
  │  POST /artwork/confirm │                       │  S3 Event →           │
  │───────────────────────►│                       │  process-image worker │
  │                        │  verify upload exists │◄──────────────────────│
  │                        │──────────────────────►│  HEAD quarantine key  │
  │                        │  size, etag ◄──────────│                       │
  │                        │                       │  GET quarantine key   │
  │                        │                       │──────────────────────►│
  │                        │                       │  stream to worker     │
  │                        │                       │                       │
  │                        │                       │  Validate:            │
  │                        │                       │  1. Magic bytes       │
  │                        │                       │  2. Sharp.metadata()  │
  │                        │                       │  3. Dimensions        │
  │                        │                       │                       │
  │                        │                       │  On success:          │
  │                        │                       │  COPY to artworks/{id}│
  │                        │                       │  DELETE quarantine    │
  │                        │                       │                       │
  │                        │                       │  POST /artwork/approved│
  │                        │◄───────────────────────────────────────────────│
  │                        │  mark artwork approved│                       │
```

### Implementation Steps

1. **Presign to quarantine prefix**
   ```typescript
   const quarantineKey = `quarantine/${artworkId}/${crypto.randomUUID()}`;
   // Issue PUT presigned URL to quarantine/ prefix (not artworks/)
   ```

2. **Post-upload confirmation endpoint** (`POST /artwork/:id/confirm`)
   ```typescript
   // HEAD the quarantine key — confirms actual upload happened
   const head = await s3.headObject({ Bucket, Key: quarantineKey });
   if (head.ContentLength > MAX_SIZE) throw new BadRequestException();
   // Queue validation job or invoke synchronously for small files
   ```

3. **Content validation worker**
   ```typescript
   import sharp from 'sharp';
   
   const buffer = await downloadFromS3(quarantineKey);
   
   // Validate magic bytes
   const ALLOWED_MAGIC = {
     jpeg: [0xFF, 0xD8, 0xFF],
     png:  [0x89, 0x50, 0x4E, 0x47],
     webp: [0x52, 0x49, 0x46, 0x46],  // RIFF....WEBP
   };
   // Check buffer[0..3] against allowlist
   
   // Validate image can be decoded
   const meta = await sharp(buffer).metadata();
   if (!meta.width || !meta.height) throw new ValidationError();
   if (meta.width > 10000 || meta.height > 10000) throw new ValidationError();
   
   // Generate trusted derivative
   const thumbnail = await sharp(buffer).resize(400, 400, { fit: 'inside' }).webp().toBuffer();
   await uploadToS3(`artworks/${artworkId}/thumb.webp`, thumbnail);
   
   // Move original to permanent location
   await s3.copyObject({ CopySource: quarantineKey, Key: `artworks/${artworkId}/original` });
   await s3.deleteObject({ Key: quarantineKey });
   ```

4. **Mark artwork as approved**
   - Update `artwork.status = 'active'` only after worker confirms
   - Rejected uploads: `artwork.status = 'image_rejected'`, quarantine key deleted

---

## Library Book Downloads

| Check | Status |
|---|---|
| `user_book_access` entitlement required | ✅ |
| Presigned GET URL TTL: 1 hour | ✅ |
| PDF content validation | ⚠ Not yet implemented |

PDF validation follows same quarantine pattern: upload to quarantine, validate with `pdf-parse` or `pdfinfo` magic bytes check, then approve to permanent location.

---

## Status Summary

| Item | Status |
|---|---|
| Declared content-type validation | ✅ Implemented |
| Unique key per upload attempt | ✅ Implemented |
| File size declaration limit | ✅ Implemented |
| Actual content validation (magic bytes + decode) | ⏭ M2 infrastructure task |
| Quarantine prefix + worker pipeline | ⏭ M2 infrastructure task |
| Image dimension limits | ⏭ M2 infrastructure task |
| Trusted derivative generation | ⏭ M2 infrastructure task |
