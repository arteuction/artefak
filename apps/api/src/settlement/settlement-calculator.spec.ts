/**
 * Phase 0.5 — Settlement Engine: pure domain layer
 *
 * Money
 * MN1  fromCents — rejects non-integer
 * MN2  plus — same currency
 * MN3  plus — currency mismatch throws
 * MN4  isNegative
 * MN5  allocate — [1,1,1] splits exactly, no cents lost
 * MN6  allocate — largest-remainder tie-break: earliest index wins
 * MN7  allocate — all-zero weights → all zero
 * MN8  allocate — single weight → full amount
 *
 * SplitProfile
 * SP1  fromKey — social_pilot_45_45_10 valid
 * SP2  fromKey — library_80_10_10 valid
 * SP3  fromKey — unknown key throws
 *
 * SettlementCalculator
 * SC1  45_45_10 on 10000 cents → 4500/4500/1000 (exact)
 * SC2  45_45_10 on 10001 cents → ops absorbs remainder
 * SC3  45_45_10 on 1 cent → artist=0, fund=0, ops=1
 * SC4  45_45_10 on 0 cents → all zero
 * SC5  library_80_10_10 on 10000 cents → 8000/1000/1000
 * SC6  non-EUR throws
 * SC7  negative gross throws
 * SC8  conservation: artist+fund+ops === gross for 20 random values
 *
 * CoAuthorSplit
 * CA1  equal weights → largest-remainder, no cents lost
 * CA2  unequal weights → correct allocation
 * CA3  empty weights throws
 *
 * FundSplit
 * FS1  two NGOs equal weights
 * FS2  single NGO gets full pool
 * FS3  empty weights throws
 */

import { jest } from '@jest/globals';
import { Money } from './money';
import { SplitProfile } from './split-profile';
import { SettlementCalculator } from './settlement-calculator';
import { CoAuthorSplit } from './co-author-split';
import { FundSplit } from './fund-split';

const calc = new SettlementCalculator();
const p45  = SplitProfile.fromKey('social_pilot_45_45_10');
const p80  = SplitProfile.fromKey('library_80_10_10');

describe('Phase 0.5 — Settlement Domain', () => {

  // ── Money ──────────────────────────────────────────────────────────────

  it('MN1: fromCents rejects non-integer', () => {
    expect(() => Money.fromCents(1.5)).toThrow();
  });

  it('MN2: plus — same currency', () => {
    expect(Money.fromCents(100).plus(Money.fromCents(50)).cents).toBe(150);
  });

  it('MN3: plus — currency mismatch throws', () => {
    expect(() => Money.fromCents(100, 'EUR').plus(Money.fromCents(100, 'USD'))).toThrow();
  });

  it('MN4: isNegative', () => {
    expect(Money.fromCents(-1).isNegative()).toBe(true);
    expect(Money.fromCents(0).isNegative()).toBe(false);
  });

  it('MN5: allocate [1,1,1] on 10 cents — sum preserved', () => {
    const parts = Money.fromCents(10).allocate([1, 1, 1]);
    expect(parts.map((p) => p.cents).reduce((a, b) => a + b, 0)).toBe(10);
  });

  it('MN6: allocate — largest-remainder: earliest index wins on tie', () => {
    // 10 cents ÷ [1,1,1] → floors [3,3,3], leftover 1 → index 0 gets it
    const parts = Money.fromCents(10).allocate([1, 1, 1]);
    expect(parts[0].cents).toBe(4);
    expect(parts[1].cents).toBe(3);
    expect(parts[2].cents).toBe(3);
  });

  it('MN7: allocate — all-zero weights → all zero', () => {
    const parts = Money.fromCents(100).allocate([0, 0, 0]);
    expect(parts.every((p) => p.cents === 0)).toBe(true);
  });

  it('MN8: allocate — single weight → full amount', () => {
    const parts = Money.fromCents(999).allocate([1]);
    expect(parts[0].cents).toBe(999);
  });

  // ── SplitProfile ───────────────────────────────────────────────────────

  it('SP1: social_pilot_45_45_10 — correct bps', () => {
    expect(p45.artistBps).toBe(4500);
    expect(p45.fundBps).toBe(4500);
    expect(p45.opsBps).toBe(1000);
  });

  it('SP2: library_80_10_10 — correct bps', () => {
    expect(p80.artistBps).toBe(8000);
    expect(p80.fundBps).toBe(1000);
    expect(p80.opsBps).toBe(1000);
  });

  it('SP3: unknown key throws', () => {
    expect(() => SplitProfile.fromKey('standard_90_10')).toThrow();
  });

  // ── SettlementCalculator ───────────────────────────────────────────────

  it('SC1: 45_45_10 on 10000 cents → 4500/4500/1000 exact', () => {
    const r = calc.calculate(Money.fromCents(10000), p45);
    expect(r.artist.cents).toBe(4500);
    expect(r.fund.cents).toBe(4500);
    expect(r.ops.cents).toBe(1000);
  });

  it('SC2: 45_45_10 on 10001 cents → ops absorbs remainder', () => {
    const r = calc.calculate(Money.fromCents(10001), p45);
    // floor(10001×4500/10000)=4500, floor(10001×4500/10000)=4500, ops=10001-4500-4500=1001
    expect(r.artist.cents).toBe(4500);
    expect(r.fund.cents).toBe(4500);
    expect(r.ops.cents).toBe(1001);
    r.assertConservation();
  });

  it('SC3: 45_45_10 on 1 cent → ops gets it', () => {
    const r = calc.calculate(Money.fromCents(1), p45);
    expect(r.artist.cents).toBe(0);
    expect(r.fund.cents).toBe(0);
    expect(r.ops.cents).toBe(1);
    r.assertConservation();
  });

  it('SC4: 45_45_10 on 0 cents → all zero', () => {
    const r = calc.calculate(Money.fromCents(0), p45);
    expect(r.artist.cents + r.fund.cents + r.ops.cents).toBe(0);
  });

  it('SC5: library_80_10_10 on 10000 cents → 8000/1000/1000', () => {
    const r = calc.calculate(Money.fromCents(10000), p80);
    expect(r.artist.cents).toBe(8000);
    expect(r.fund.cents).toBe(1000);
    expect(r.ops.cents).toBe(1000);
    r.assertConservation();
  });

  it('SC6: non-EUR throws', () => {
    expect(() => calc.calculate(Money.fromCents(1000, 'USD'), p45)).toThrow('Only EUR');
  });

  it('SC7: negative gross throws', () => {
    expect(() => calc.calculate(Money.fromCents(-1), p45)).toThrow('negative');
  });

  it('SC8: conservation holds for 20 random values', () => {
    for (let i = 0; i < 20; i++) {
      const cents = Math.floor(Math.random() * 1_000_000) + 1;
      const profile = i % 2 === 0 ? p45 : p80;
      const r = calc.calculate(Money.fromCents(cents), profile);
      r.assertConservation();
    }
  });

  // ── CoAuthorSplit ──────────────────────────────────────────────────────

  it('CA1: equal weights — no cents lost', () => {
    const pool   = Money.fromCents(10);
    const result = CoAuthorSplit.split(pool, { a1: 1, a2: 1, a3: 1 });
    const sum    = Object.values(result).reduce((s, m) => s + m.cents, 0);
    expect(sum).toBe(10);
  });

  it('CA2: unequal weights — allocates proportionally', () => {
    const pool   = Money.fromCents(10000);
    const result = CoAuthorSplit.split(pool, { lead: 7, collab: 3 });
    expect(result.lead.cents).toBe(7000);
    expect(result.collab.cents).toBe(3000);
  });

  it('CA3: empty weights throws', () => {
    expect(() => CoAuthorSplit.split(Money.fromCents(1000), {})).toThrow();
  });

  // ── FundSplit ──────────────────────────────────────────────────────────

  it('FS1: two NGOs equal weights — no cents lost', () => {
    const pool   = Money.fromCents(9000);
    const result = FundSplit.split(pool, { ngo1: 1, ngo2: 1 });
    const sum    = Object.values(result).reduce((s, m) => s + m.cents, 0);
    expect(sum).toBe(9000);
    expect(result.ngo1.cents).toBe(4500);
    expect(result.ngo2.cents).toBe(4500);
  });

  it('FS2: single NGO gets full pool', () => {
    const pool   = Money.fromCents(4500);
    const result = FundSplit.split(pool, { ngo1: 1 });
    expect(result.ngo1.cents).toBe(4500);
  });

  it('FS3: empty weights throws', () => {
    expect(() => FundSplit.split(Money.fromCents(1000), {})).toThrow();
  });
});
