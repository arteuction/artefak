type Variant = 'default' | 'live' | 'sold' | 'draft' | 'auction' | 'outline';

interface Props {
    variant?: Variant;
    children: React.ReactNode;
    className?: string;
    pulse?: boolean;
}

const variantClasses: Record<Variant, string> = {
    default:  'bg-[var(--color-bg-muted)] text-[var(--color-text-muted)]',
    live:     'bg-[var(--color-live-bg)] text-[var(--color-live)]',
    sold:     'bg-[var(--color-sold-bg)] text-[var(--color-sold-text)]',
    draft:    'bg-[var(--color-draft-bg)] text-[var(--color-draft-text)]',
    auction:  'bg-amber-50 text-amber-700',
    outline:  'border border-[var(--color-border)] text-[var(--color-text-muted)] bg-transparent',
};

export default function Badge({ variant = 'default', pulse = false, className = '', children }: Props) {
    return (
        <span
            className={[
                'inline-flex items-center gap-1 rounded-[var(--radius-full)] px-2 py-0.5 text-xs font-medium',
                variantClasses[variant],
                pulse ? 'animate-pulse' : '',
                className,
            ].join(' ')}
        >
            {children}
        </span>
    );
}
