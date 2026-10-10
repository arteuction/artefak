interface Props {
    title: string;
    description?: string;
    action?: React.ReactNode;
}

export default function EmptyState({ title, description, action }: Props) {
    return (
        <div className="flex flex-col items-center justify-center py-16 px-4 text-center">
            <div className="mb-4 h-12 w-12 rounded-full bg-[var(--color-bg-muted)] flex items-center justify-center text-2xl">
                🖼
            </div>
            <p className="font-medium text-[var(--color-text)]">{title}</p>
            {description && (
                <p className="mt-1 text-sm text-[var(--color-text-muted)] max-w-xs">{description}</p>
            )}
            {action && <div className="mt-4">{action}</div>}
        </div>
    );
}
