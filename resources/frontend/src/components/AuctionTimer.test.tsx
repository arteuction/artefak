import { render, screen, act } from '@testing-library/react';
import { vi, describe, it, expect, beforeEach, afterEach } from 'vitest';
import AuctionTimer, { computeTimer, URGENCY_THRESHOLD_SECONDS } from './AuctionTimer';

// ── computeTimer unit tests ─────────────────────────────────────────────────

describe('computeTimer', () => {
    const NOW = 1_700_000_000_000; // fixed epoch ms

    it('formats hours/minutes/seconds when > 1h remaining', () => {
        const endsAt = new Date(NOW + 3_661_000).toISOString(); // 1h 1m 1s
        const { formatted, state } = computeTimer(endsAt, NOW);
        expect(formatted).toBe('1h 1m 1s');
        expect(state).toBe('normal');
    });

    it('formats minutes/seconds when < 1h remaining', () => {
        const endsAt = new Date(NOW + 65_000).toISOString(); // 1m 5s
        const { formatted, state } = computeTimer(endsAt, NOW);
        expect(formatted).toBe('1m 5s');
        expect(state).toBe('normal');
    });

    it('formats seconds-only when < 1m remaining', () => {
        const endsAt = new Date(NOW + 45_000).toISOString();
        const { formatted, remainingSeconds, state } = computeTimer(endsAt, NOW);
        expect(formatted).toBe('45s');
        expect(remainingSeconds).toBe(45);
        expect(state).toBe('normal');
    });

    it('returns ending_soon when remainingSeconds <= URGENCY_THRESHOLD_SECONDS', () => {
        const endsAt = new Date(NOW + URGENCY_THRESHOLD_SECONDS * 1_000).toISOString();
        expect(computeTimer(endsAt, NOW).state).toBe('ending_soon');
    });

    it('returns ending_soon at exactly the boundary (ceil edge)', () => {
        // 30 000 ms → ceil(30000/1000) = 30 → ending_soon
        const endsAt = new Date(NOW + 30_000).toISOString();
        expect(computeTimer(endsAt, NOW).state).toBe('ending_soon');
    });

    it('returns ended when diffMs <= 0', () => {
        const endsAt = new Date(NOW - 1).toISOString();
        const { formatted, state } = computeTimer(endsAt, NOW);
        expect(state).toBe('ended');
        expect(formatted).toBe('Ended');
    });

    it('returns invalid for a non-date string', () => {
        expect(computeTimer('not-a-date', NOW).state).toBe('invalid');
    });
});

// ── Component tests ─────────────────────────────────────────────────────────

describe('AuctionTimer component', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });
    afterEach(() => {
        vi.useRealTimers();
    });

    function makeEndsAt(fromNow: number) {
        return new Date(Date.now() + fromNow).toISOString();
    }

    it('renders the formatted countdown in the normal state', () => {
        const endsAt = makeEndsAt(120_000); // 2 minutes
        let now = Date.now();
        render(<AuctionTimer endsAt={endsAt} clock={() => now} />);
        const el = screen.getByTestId('auction-timer');
        expect(el).toHaveAttribute('data-state', 'normal');
        expect(el.textContent).toMatch(/2m/);
    });

    it('renders nothing for a null endsAt', () => {
        const { container } = render(<AuctionTimer endsAt={null} />);
        expect(container.firstChild).toBeNull();
    });

    it('renders nothing for an invalid timestamp', () => {
        const { container } = render(<AuctionTimer endsAt="not-a-date" />);
        expect(container.firstChild).toBeNull();
    });

    it('transitions to ending_soon state when <= 30s remain', () => {
        let now = Date.now();
        const endsAt = new Date(now + 120_000).toISOString();
        const clock = () => now;
        render(<AuctionTimer endsAt={endsAt} clock={clock} />);

        // Advance to the ending_soon boundary
        act(() => {
            now += 91_000; // 120s → 29s left
            vi.advanceTimersByTime(91_000);
        });

        const el = screen.getByTestId('auction-timer');
        expect(el).toHaveAttribute('data-state', 'ending_soon');
    });

    it('announces "Final 30 seconds remaining" when entering ending_soon', () => {
        let now = Date.now();
        const endsAt = new Date(now + 31_000).toISOString(); // 31s away
        const clock = () => now;
        render(<AuctionTimer endsAt={endsAt} clock={clock} />);

        act(() => {
            now += 2_000; // 29s left → crossing the 30s boundary
            vi.advanceTimersByTime(2_000);
        });

        const live = document.querySelector('[aria-live="assertive"]');
        expect(live?.textContent).toContain('Final 30 seconds');
    });

    it('shows "Ended" and announces when the auction expires', () => {
        let now = Date.now();
        const endsAt = new Date(now + 2_000).toISOString();
        const clock = () => now;
        render(<AuctionTimer endsAt={endsAt} clock={clock} />);

        act(() => {
            now += 3_000;
            vi.advanceTimersByTime(3_000);
        });

        const el = screen.getByTestId('auction-timer');
        expect(el).toHaveAttribute('data-state', 'ended');
        expect(el.textContent).toBe('Ended');

        const live = document.querySelector('[aria-live="assertive"]');
        expect(live?.textContent).toContain('Auction has ended');
    });

    it('handles end-time extension without resetting incorrectly', () => {
        let now = Date.now();
        const firstEndsAt = new Date(now + 10_000).toISOString();
        const clock = () => now;

        const { rerender } = render(<AuctionTimer endsAt={firstEndsAt} clock={clock} />);
        let el = screen.getByTestId('auction-timer');
        expect(el).toHaveAttribute('data-state', 'ending_soon');

        // Server extends by 2 minutes
        const extendedEndsAt = new Date(now + 130_000).toISOString();
        rerender(<AuctionTimer endsAt={extendedEndsAt} clock={clock} />);

        el = screen.getByTestId('auction-timer');
        expect(el).toHaveAttribute('data-state', 'normal');
        expect(el.textContent).toMatch(/2m/);
    });

    it('cleans up the interval on unmount', () => {
        const clearIntervalSpy = vi.spyOn(globalThis, 'clearInterval');
        let now = Date.now();
        const endsAt = new Date(now + 60_000).toISOString();
        const { unmount } = render(<AuctionTimer endsAt={endsAt} clock={() => now} />);
        unmount();
        expect(clearIntervalSpy).toHaveBeenCalled();
        clearIntervalSpy.mockRestore();
    });

    it('visible counter is aria-hidden (no per-second SR noise)', () => {
        let now = Date.now();
        const endsAt = new Date(now + 60_000).toISOString();
        render(<AuctionTimer endsAt={endsAt} clock={() => now} />);
        const el = screen.getByTestId('auction-timer');
        expect(el).toHaveAttribute('aria-hidden', 'true');
    });
});
