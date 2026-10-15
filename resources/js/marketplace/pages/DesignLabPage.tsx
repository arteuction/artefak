import { useState } from 'react';

const SPECIMEN_BG = [
    { label: 'bg', value: 'var(--color-bg)' },
    { label: 'bg-subtle', value: 'var(--color-bg-subtle)' },
    { label: 'bg-muted', value: 'var(--color-bg-muted)' },
];

const SPECIMEN_TEXT_BG = 'var(--color-bg)';

export default function DesignLabPage() {
    const [activeBg, setActiveBg] = useState(0);

    return (
        <div style={{ fontFamily: 'var(--font-ui)', background: 'var(--color-bg)', minHeight: '100vh', padding: '2rem' }}>
            <header style={{ marginBottom: '3rem', borderBottom: '1px solid var(--color-border)', paddingBottom: '1.5rem' }}>
                <p style={{ fontFamily: 'var(--font-ui)', fontSize: '0.75rem', letterSpacing: '0.12em', textTransform: 'uppercase', color: 'var(--color-text-faint)', marginBottom: '0.5rem' }}>
                    ARTeuCtion · Phase 161 · Design Lab
                </p>
                <h1 style={{ fontFamily: 'var(--font-editorial)', fontWeight: 300, fontSize: '2.5rem', color: 'var(--color-text)', margin: 0 }}>
                    Design System Foundation
                </h1>
            </header>

            {/* ── Colour palette ─────────────────────────────────────── */}
            <section style={{ marginBottom: '3rem' }}>
                <SectionTitle>Colour palette</SectionTitle>
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(140px, 1fr))', gap: '0.75rem' }}>
                    {[
                        { label: '--color-bg', value: '#F8F6F1' },
                        { label: '--color-bg-subtle', value: '#F0EDE6' },
                        { label: '--color-bg-muted', value: '#E8E4DC' },
                        { label: '--color-border', value: '#D9D4CB' },
                        { label: '--color-border-strong', value: '#BFB8AD' },
                        { label: '--color-text', value: '#1A1A1A' },
                        { label: '--color-text-muted', value: '#6B6560' },
                        { label: '--color-text-faint', value: '#9B948D' },
                        { label: '--color-accent', value: '#C4622D' },
                        { label: '--color-accent-hover', value: '#A8511F' },
                        { label: '--color-urgent', value: '#B91C1C' },
                    ].map(({ label, value }) => (
                        <div key={label}>
                            <div style={{ height: 48, background: value, border: '1px solid var(--color-border)', borderRadius: 'var(--radius-md)' }} />
                            <p style={{ fontSize: '0.65rem', color: 'var(--color-text-muted)', marginTop: '0.375rem', fontFamily: 'monospace' }}>{label}</p>
                            <p style={{ fontSize: '0.65rem', color: 'var(--color-text-faint)', margin: 0, fontFamily: 'monospace' }}>{value}</p>
                        </div>
                    ))}
                </div>
            </section>

            {/* ── Typography specimen ────────────────────────────────── */}
            <section style={{ marginBottom: '3rem' }}>
                <SectionTitle>Typography — Latin</SectionTitle>
                <div style={{ background: SPECIMEN_TEXT_BG, border: '1px solid var(--color-border)', borderRadius: 'var(--radius-lg)', padding: '2rem' }}>
                    <TypeRow size="3.5rem" weight={300} italic={false}>Artwork title — light</TypeRow>
                    <TypeRow size="3.5rem" weight={300} italic>Artwork title — light italic</TypeRow>
                    <TypeRow size="2.5rem" weight={400} italic={false}>Exhibition catalogue entry</TypeRow>
                    <TypeRow size="2.5rem" weight={400} italic>Exhibition catalogue entry — italic</TypeRow>
                    <TypeRow size="1.75rem" weight={600} italic={false}>Gallery name — semibold</TypeRow>
                    <TypeRow size="1.25rem" weight={400} italic={false}>Lot description body text. Fine oil on canvas, 120 × 90 cm, signed lower right.</TypeRow>
                </div>
            </section>

            {/* ── Bulgarian (Cyrillic) validation gate ──────────────── */}
            <section style={{ marginBottom: '3rem' }}>
                <SectionTitle>Typography — Кирилица (Bulgarian validation gate)</SectionTitle>
                <p style={{ fontSize: '0.8rem', color: 'var(--color-text-muted)', marginBottom: '1rem' }}>
                    Cormorant Garamond must render Cyrillic at 48 – 64 px without fallback to system serif.
                    Inspect glyphs: Й Щ Ъ Ь Ю Я — these are the hardest letters.
                </p>
                <div style={{ background: SPECIMEN_TEXT_BG, border: '1px solid var(--color-border)', borderRadius: 'var(--radius-lg)', padding: '2rem' }}>
                    <TypeRow size="4rem" weight={300} italic={false}>Художествена галерия</TypeRow>
                    <TypeRow size="4rem" weight={300} italic>Изложба на съвременно изкуство</TypeRow>
                    <TypeRow size="3rem" weight={400} italic={false}>Произведение на изкуството</TypeRow>
                    <TypeRow size="3rem" weight={400} italic>Аукционна зала — Ъ Й Щ Ю Я</TypeRow>
                    <TypeRow size="2rem" weight={600} italic={false}>Стартова цена: 2 400 EUR</TypeRow>
                    <TypeRow size="1.25rem" weight={400} italic={false}>
                        Маслена живопис върху платно, 120 × 90 см, подписана долу вдясно.
                        Произхожда от частна колекция, София, придобита 1987 г.
                    </TypeRow>
                </div>
            </section>

            {/* ── UI type scale ─────────────────────────────────────── */}
            <section style={{ marginBottom: '3rem' }}>
                <SectionTitle>UI type scale (Inter)</SectionTitle>
                <div style={{ display: 'flex', flexDirection: 'column', gap: '0.5rem' }}>
                    {[
                        { size: '0.6875rem', label: 'xs · 11px · label / metadata' },
                        { size: '0.75rem',   label: 'sm · 12px · caption' },
                        { size: '0.875rem',  label: 'base · 14px · body UI' },
                        { size: '1rem',      label: 'md · 16px · comfortable body' },
                        { size: '1.125rem',  label: 'lg · 18px · subheading' },
                    ].map(({ size, label }) => (
                        <p key={size} style={{ fontFamily: 'var(--font-ui)', fontSize: size, color: 'var(--color-text)', margin: 0 }}>
                            {label}
                        </p>
                    ))}
                </div>
            </section>

            {/* ── Accent + interactive ──────────────────────────────── */}
            <section style={{ marginBottom: '3rem' }}>
                <SectionTitle>Accent &amp; interactive</SectionTitle>
                <div style={{ display: 'flex', gap: '1rem', flexWrap: 'wrap', alignItems: 'center' }}>
                    <button style={{
                        background: 'var(--color-action)',
                        color: 'var(--color-action-fg)',
                        border: 'none',
                        borderRadius: 'var(--radius-md)',
                        padding: '0.625rem 1.25rem',
                        fontFamily: 'var(--font-ui)',
                        fontSize: '0.875rem',
                        fontWeight: 500,
                        cursor: 'pointer',
                        letterSpacing: '0.02em',
                    }}>
                        Place bid
                    </button>
                    <button style={{
                        background: 'transparent',
                        color: 'var(--color-action)',
                        border: '1px solid var(--color-action)',
                        borderRadius: 'var(--radius-md)',
                        padding: '0.625rem 1.25rem',
                        fontFamily: 'var(--font-ui)',
                        fontSize: '0.875rem',
                        fontWeight: 500,
                        cursor: 'pointer',
                    }}>
                        Make offer
                    </button>
                    <span style={{
                        display: 'inline-flex',
                        alignItems: 'center',
                        gap: '0.375rem',
                        background: 'var(--color-live-bg)',
                        color: 'var(--color-live)',
                        borderRadius: 'var(--radius-full)',
                        padding: '0.25rem 0.75rem',
                        fontSize: '0.75rem',
                        fontFamily: 'var(--font-ui)',
                        fontWeight: 600,
                        letterSpacing: '0.06em',
                        textTransform: 'uppercase',
                    }}>
                        <span style={{ width: 6, height: 6, borderRadius: '50%', background: 'var(--color-live)', display: 'inline-block' }} />
                        Live
                    </span>
                    <span style={{
                        display: 'inline-block',
                        background: 'var(--color-urgent-bg)',
                        color: 'var(--color-urgent)',
                        borderRadius: 'var(--radius-full)',
                        padding: '0.25rem 0.75rem',
                        fontSize: '0.875rem',
                        fontFamily: 'var(--font-ui)',
                        fontWeight: 700,
                        letterSpacing: '0.04em',
                    }}>
                        0:28
                    </span>
                </div>
            </section>

            {/* ── Background surfaces ───────────────────────────────── */}
            <section style={{ marginBottom: '3rem' }}>
                <SectionTitle>Surface layers</SectionTitle>
                <div style={{ display: 'flex', gap: '0.5rem', marginBottom: '1rem' }}>
                    {SPECIMEN_BG.map((bg, i) => (
                        <button
                            key={bg.label}
                            onClick={() => setActiveBg(i)}
                            style={{
                                fontSize: '0.75rem',
                                fontFamily: 'var(--font-ui)',
                                padding: '0.25rem 0.75rem',
                                borderRadius: 'var(--radius-full)',
                                border: '1px solid var(--color-border)',
                                background: i === activeBg ? 'var(--color-action)' : 'transparent',
                                color: i === activeBg ? 'var(--color-action-fg)' : 'var(--color-text-muted)',
                                cursor: 'pointer',
                            }}
                        >
                            {bg.label}
                        </button>
                    ))}
                </div>
                <div style={{
                    background: SPECIMEN_BG[activeBg].value,
                    border: '1px solid var(--color-border)',
                    borderRadius: 'var(--radius-lg)',
                    padding: '1.5rem',
                    display: 'grid',
                    gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))',
                    gap: '1rem',
                }}>
                    {[1, 2, 3].map(i => (
                        <div key={i} style={{
                            background: 'var(--color-bg)',
                            border: '1px solid var(--color-border)',
                            borderRadius: 'var(--radius-md)',
                            padding: '1rem',
                        }}>
                            <div style={{ height: 80, background: 'var(--color-bg-muted)', borderRadius: 'var(--radius-sm)', marginBottom: '0.75rem' }} />
                            <p style={{ fontFamily: 'var(--font-editorial)', fontSize: '1rem', fontWeight: 400, color: 'var(--color-text)', margin: '0 0 0.25rem' }}>
                                Artwork {i}
                            </p>
                            <p style={{ fontFamily: 'var(--font-ui)', fontSize: '0.75rem', color: 'var(--color-text-muted)', margin: 0 }}>
                                Artist Name · 2024
                            </p>
                        </div>
                    ))}
                </div>
            </section>
        </div>
    );
}

function SectionTitle({ children }: { children: React.ReactNode }) {
    return (
        <p style={{
            fontFamily: 'var(--font-ui)',
            fontSize: '0.6875rem',
            letterSpacing: '0.1em',
            textTransform: 'uppercase',
            color: 'var(--color-text-faint)',
            marginBottom: '1rem',
            fontWeight: 500,
        }}>
            {children}
        </p>
    );
}

function TypeRow({ size, weight, italic, children }: {
    size: string;
    weight: number;
    italic: boolean;
    children: React.ReactNode;
}) {
    return (
        <p style={{
            fontFamily: 'var(--font-editorial)',
            fontSize: size,
            fontWeight: weight,
            fontStyle: italic ? 'italic' : 'normal',
            color: 'var(--color-text)',
            margin: '0 0 0.5rem',
            lineHeight: 1.15,
        }}>
            {children}
        </p>
    );
}
