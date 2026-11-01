import { useEffect } from 'react';

export function useDocTitle(title: string | null | undefined): void {
    useEffect(() => {
        if (!title) return;
        const prev = document.title;
        document.title = `${title} — ARTeuCtion`;
        return () => { document.title = prev; };
    }, [title]);
}
