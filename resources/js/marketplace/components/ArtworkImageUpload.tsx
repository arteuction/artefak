import Uppy from '@uppy/core';
import AwsS3 from '@uppy/aws-s3';
import Dashboard from '@uppy/dashboard';
import { UppyContextProvider, useUppyContext } from '@uppy/react';
import { useMemo, useEffect, useRef } from 'react';

// CSS side-effects handled by Vite (vite-env.d.ts provides the module declarations)
import '@uppy/core/dist/style.min.css';
import '@uppy/dashboard/dist/style.min.css';

interface Props {
    artworkId: number;
    /** Called with the confirmed S3 key after the upload + confirm cycle completes. */
    onComplete?: (imageKey: string) => void;
}

function getCsrf(): string {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
}

/** Inner component: mounts the Uppy Dashboard into its container ref. */
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
                note: 'JPEG, PNG, WebP or TIFF up to 50 MB',
                proudlyDisplayPoweredByUppy: false,
            });
        }
    }, [uppy]);

    useEffect(() => {
        const handler = async (_file: unknown, response: { uploadURL?: string }) => {
            const key = response.uploadURL
                ? new URL(response.uploadURL).pathname.slice(1)
                : (_file as { meta?: { key?: string } }).meta?.key ?? '';

            await fetch(`/api/v1/artworks/${artworkId}/images/confirm`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrf(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({ key }),
            });

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
 *   1. POST /api/v1/artworks/{id}/images/presign  → { url, key }  (via signRequest)
 *   2. PUT directly to S3 using the presigned URL (AwsS3 plugin)
 *   3. POST /api/v1/artworks/{id}/images/confirm  → triggers derivative generation
 */
export default function ArtworkImageUpload({ artworkId, onComplete }: Props) {
    const uppy = useMemo(
        () =>
            new Uppy({
                restrictions: {
                    maxNumberOfFiles: 1,
                    allowedFileTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/tiff'],
                    maxFileSize: 50 * 1024 * 1024,
                },
                autoProceed: false,
            }).use(AwsS3, {
                async signRequest(request) {
                    const res = await fetch(`/api/v1/artworks/${artworkId}/images/presign`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': getCsrf(),
                            Accept: 'application/json',
                        },
                        body: JSON.stringify({
                            method: request.method,
                            key: request.key,
                        }),
                    });
                    if (!res.ok) throw new Error('Failed to obtain presigned URL');
                    return res.json() as Promise<{ url: string; key?: string; headers?: Record<string, string> }>;
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
