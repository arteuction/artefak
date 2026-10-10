import Uppy from '@uppy/core';
import AwsS3 from '@uppy/aws-s3';
import Dashboard from '@uppy/dashboard';
import { UppyContextProvider, useUppyContext } from '@uppy/react';
import { useMemo, useEffect, useRef } from 'react';

import '@uppy/core/dist/style.min.css';
import '@uppy/dashboard/dist/style.min.css';

interface Props {
    artworkId: number;
    onComplete?: (imageKey: string) => void;
}

function getCsrf(): string {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
}

function extensionFromMime(type: string): string {
    switch (type) {
        case 'image/jpeg': return 'jpg';
        case 'image/png':  return 'png';
        case 'image/webp': return 'webp';
        default:           return 'jpg';
    }
}

function UploadWidget({ artworkId, onComplete }: Props) {
    const containerRef = useRef<HTMLDivElement>(null);
    const { uppy } = useUppyContext();

    useEffect(() => {
        if (!containerRef.current) return;
        if (!uppy.getPlugin('Dashboard')) {
            uppy.use(Dashboard, {
                inline: true,
                target: containerRef.current,
                height: 350,
                hideProgressDetails: false,
                note: 'JPEG, PNG or WebP up to 10 MB',
                proudlyDisplayPoweredByUppy: false,
            });
        }
    }, [uppy]);

    useEffect(() => {
        const handler = async (_file: unknown, response: { uploadURL?: string }) => {
            // Confirm the upload; the key is already stored on the artwork from the presign step.
            await fetch(`/api/v1/artworks/${artworkId}/images/confirm`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrf(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({}),
            });

            const key = response.uploadURL
                ? new URL(response.uploadURL).pathname.slice(1)
                : '';
            onComplete?.(key);
        };

        uppy.on('upload-success', handler);
        return () => { uppy.off('upload-success', handler); };
    }, [uppy, artworkId, onComplete]);

    return <div ref={containerRef} className="artwork-image-upload" />;
}

/**
 * Artist image upload widget for a single Artwork.
 *
 * Flow:
 *   1. signRequest calls POST /api/v1/artworks/{id}/images/presign → { upload_url, key }
 *      The backend generates the S3 key; the key in PresignedResponse overrides Uppy's key.
 *   2. PUT directly to S3 using the presigned URL (AwsS3 plugin)
 *   3. upload-success handler calls POST /api/v1/artworks/{id}/images/confirm
 */
export default function ArtworkImageUpload({ artworkId, onComplete }: Props) {
    const uppy = useMemo(
        () =>
            new Uppy({
                restrictions: {
                    maxNumberOfFiles: 1,
                    allowedFileTypes: ['image/jpeg', 'image/png', 'image/webp'],
                    maxFileSize: 10 * 1024 * 1024,
                },
                autoProceed: false,
            }).use(AwsS3, {
                async signRequest(request) {
                    // request.key is Uppy's suggested key; we derive extension from it
                    const ext = extensionFromMime(
                        request.key ? request.key.split('.').pop() ?? '' : '',
                    ) || 'jpg';

                    const res = await fetch(`/api/v1/artworks/${artworkId}/images/presign`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrf(),
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({ extension: ext }),
                    });
                    if (!res.ok) throw new Error('Failed to obtain presigned URL');
                    const data = await res.json() as { upload_url: string; key: string };
                    // Return the server-generated key so Uppy uses it in upload-success
                    return { url: data.upload_url, key: data.key };
                },
            }),
        [artworkId],
    );

    return (
        <UppyContextProvider uppy={uppy}>
            <UploadWidget artworkId={artworkId} onComplete={onComplete} />
        </UppyContextProvider>
    );
}
