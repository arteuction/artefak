import type { HTMLAttributes } from 'react';

interface CardProps extends HTMLAttributes<HTMLDivElement> {
    padding?: boolean;
}

export default function Card({ padding = true, className = '', children, ...rest }: CardProps) {
    return (
        <div
            className={[
                'rounded-[var(--radius-lg)] border border-[var(--color-border)] bg-[var(--color-bg)]',
                padding ? 'p-5' : '',
                className,
            ].join(' ')}
            {...rest}
        >
            {children}
        </div>
    );
}

export function CardHeader({ className = '', children, ...rest }: HTMLAttributes<HTMLDivElement>) {
    return (
        <div className={['mb-4', className].join(' ')} {...rest}>
            {children}
        </div>
    );
}

export function CardTitle({ className = '', children, ...rest }: HTMLAttributes<HTMLHeadingElement>) {
    return (
        <h3 className={['text-base font-semibold text-[var(--color-text)]', className].join(' ')} {...rest}>
            {children}
        </h3>
    );
}
