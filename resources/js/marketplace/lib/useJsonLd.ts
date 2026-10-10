import { useEffect } from 'react';

export function useJsonLd(data: Record<string, unknown> | null): void {
    useEffect(() => {
        if (!data) return;
        const script = document.createElement('script');
        script.type = 'application/ld+json';
        script.text = JSON.stringify(data);
        script.id = `jsonld-${data['@type'] ?? 'schema'}`;
        document.head.appendChild(script);
        return () => {
            document.head.removeChild(script);
        };
    }, [data]);
}
