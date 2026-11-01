import { useEffect, useRef, useState } from 'react';

export const URGENCY_THRESHOLD_SECONDS = 30;

type TimerState = 'normal' | 'ending_soon' | 'ended' | 'invalid';

export type TimerData = {
    formatted: string;
    remainingSeconds: number;
    state: TimerState;
};

export function computeTimer(endsAt: string, now: number): TimerData {
    const endMs = new Date(endsAt).getTime();
    if (isNaN(endMs)) {
        return { formatted: '', remainingSeconds: 0, state: 'invalid' };
    }

    const diffMs = endMs - now;
    if (diffMs <= 0) {
        return { formatted: 'Ended', remainingSeconds: 0, state: 'ended' };
    }

    const totalSec = Math.ceil(diffMs / 1_000);
    const h = Math.floor(totalSec / 3_600);
    const m = Math.floor((totalSec % 3_600) / 60);
    const s = totalSec % 60;

    return {
        formatted: h > 0 ? `${h}h ${m}m ${s}s` : m > 0 ? `${m}m ${s}s` : `${s}s`,
        remainingSeconds: totalSec,
        state: totalSec <= URGENCY_THRESHOLD_SECONDS ? 'ending_soon' : 'normal',
    };
}

type Props = {
    endsAt: string | null;
    /** Injectable clock — pass `() => fakeNow` in tests. Defaults to `Date.now`. */
    clock?: () => number;
};

export default function AuctionTimer({ endsAt, clock = Date.now }: Props) {
    const [timer, setTimer] = useState<TimerData | null>(
        endsAt ? computeTimer(endsAt, clock()) : null,
    );
    const [announcement, setAnnouncement] = useState('');

    // Announce only on meaningful transitions, not every tick.
    // The visible counter is aria-hidden; screen readers get this polite region.
    const announcementRef = useRef('');

    useEffect(() => {
        if (!endsAt) {
            setTimer(null);
            return;
        }

        const first = computeTimer(endsAt, clock());
        setTimer(first);

        if (first.state === 'invalid') return;

        let prevState: TimerState = first.state;

        const tick = () => {
            const next = computeTimer(endsAt, clock());

            if (prevState === 'normal' && next.state === 'ending_soon') {
                const msg = 'Final 30 seconds remaining';
                announcementRef.current = msg;
                setAnnouncement(msg);
            } else if (prevState !== 'ended' && next.state === 'ended') {
                const msg = 'Auction has ended';
                announcementRef.current = msg;
                setAnnouncement(msg);
            }

            prevState = next.state;
            setTimer(next);
        };

        const id = setInterval(tick, 1_000);
        return () => clearInterval(id);
    }, [endsAt, clock]);

    if (!timer || timer.state === 'invalid') return null;

    const isUrgent = timer.state === 'ending_soon';
    const isEnded = timer.state === 'ended';

    return (
        <>
            {/* Persistent live region — text changes trigger SR announcements on state transitions only */}
            <span
                aria-live="assertive"
                aria-atomic="true"
                className="sr-only"
            >
                {announcement}
            </span>

            {/* Visible counter — aria-hidden prevents noisy per-second announcements */}
            {isEnded ? (
                <span
                    data-testid="auction-timer"
                    data-state="ended"
                    aria-hidden="true"
                    style={{
                        fontFamily: 'var(--font-ui)',
                        fontVariantNumeric: 'tabular-nums',
                        color: 'var(--color-text-faint)',
                    }}
                >
                    Ended
                </span>
            ) : (
                <span
                    data-testid="auction-timer"
                    data-state={timer.state}
                    aria-hidden="true"
                    style={{
                        fontFamily: 'var(--font-ui)',
                        fontVariantNumeric: 'tabular-nums',
                        color: isUrgent ? 'var(--color-urgent)' : 'var(--color-text-muted)',
                        fontWeight: isUrgent ? 700 : 400,
                        // No CSS transition here — respects prefers-reduced-motion automatically
                        // since there is no animation, only a state-driven style swap.
                    }}
                >
                    {timer.formatted}
                </span>
            )}
        </>
    );
}
