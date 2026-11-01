import type { InputHTMLAttributes } from 'react';

interface Props extends InputHTMLAttributes<HTMLInputElement> {
    label?: string;
    error?: string;
    hint?: string;
}

export default function Input({ label, error, hint, id, className = '', ...rest }: Props) {
    const inputId = id ?? label?.toLowerCase().replace(/\s+/g, '-');
    return (
        <div className="w-full">
            {label && (
                <label htmlFor={inputId} className="block text-sm font-medium text-[var(--color-text)] mb-1">
                    {label}
                    {rest.required && <span className="ml-1 text-red-500">*</span>}
                </label>
            )}
            <input
                id={inputId}
                className={[
                    'w-full rounded-[var(--radius-sm)] border px-3 py-2 text-sm',
                    'bg-[var(--color-bg)] text-[var(--color-text)]',
                    'placeholder:text-[var(--color-text-faint)]',
                    'focus:outline-none focus:ring-2 focus:ring-[var(--color-accent)] focus:ring-offset-0',
                    error
                        ? 'border-red-500 focus:ring-red-400'
                        : 'border-[var(--color-border)] focus:border-[var(--color-border-strong)]',
                    className,
                ].join(' ')}
                {...rest}
            />
            {error && <p className="mt-1 text-xs text-red-600">{error}</p>}
            {!error && hint && <p className="mt-1 text-xs text-[var(--color-text-faint)]">{hint}</p>}
        </div>
    );
}
