import Uppy from '@uppy/core';
import AwsS3 from '@uppy/aws-s3';
import { Dashboard } from '@uppy/dashboard';
import { useUppy } from '@uppy/react';
import { useEffect, useRef } from 'react';

import '@uppy/core/dist/style.min.css';
import '@uppy/dashboard/dist/style.min.css';

interface Props {
    artworkId: number;
    /** Called with the confirmed S3 key after the upload + confirm cycle completes. */
    onComplete?: (imageKey: string) => void;
}

/**
 * Artist image upload widget for a single Artwork.
 *
 * Flow:
 *   1. POST /api/v1/artworks/{id}/images/presign  → { url, fields, key }
 *   2. PUT directly to S3 using the presigned URL (AwsS3 plugin)
 *   3. POST /api/v1/artworks/{id}/images/confirm  → triggers derivative generation
 */
export default function ArtworkImageUpload({ artworkId, onComplete }: Props) {
    const containerRef = useRef<HTMLDivElement>(null);

    const uppy = useUppy(() => {
        const instance = new Uppy({
            restrictions: {
                maxNumberOfFiles: 1,
                allowedFileTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/tiff'],
                maxFileSize: 50 * 1024 * 1024, // 50 MB
            },
            autoProceed: false,
        });

        instance.use(AwsS3, {
            async getUploadParameters(file) {
                const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                    ?.content ?? '';
                const res = await fetch(`/api/v1/artworks/${artworkId}/images/presign`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ filename: file.name, content_type: file.type }),
                });
                if (!res.ok) throw new Error('Failed to obtain presigned URL');
                const { url, fields, key } = await res.json();
                return { method: 'POST' as const, url, fields, headers: {}, key };
            },
        });

        instance.on('upload-success', async (_file, response) => {
            // After S3 upload, confirm so Laravel triggers derivative generation.
            const key = response.uploadURL
                ? new URL(response.uploadURL).pathname.slice(1)
                : (_file as { meta?: { key?: string } }).meta?.key ?? '';

            const csrf = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                ?.content ?? '';

            await fetch(`/api/v1/artworks/${artworkId}/images/confirm`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ key }),
            });

            onComplete?.(key);
        });

        return instance;
    });

    useEffect(() => {
        if (!containerRef.current) return;
        const dashboard = uppy.getPlugin('Dashboard');
        if (!dashboard) {
            uppy.use(Dashboard, {
                inline: true,
                target: containerRef.current,
                height: 350,
                showProgressDetails: true,
                note: 'JPEG, PNG, WebP or TIFF up to 50 MB',
                proudlyDisplayPoweredByUppy: false,
            });
        }
        return () => {
            uppy.close();
        };
    }, []);  // eslint-disable-line react-hooks/exhaustive-deps

    return <div ref={containerRef} className="artwork-image-upload" />;
}
