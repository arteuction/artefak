import { Money } from './money';
import { SplitProfile } from './split-profile';
import { SettlementResult } from './settlement-result';

/**
 * Pure domain calculator — no DB, no Stripe.
 *
 * P0 formula (mirrors PHP exactly):
 *   artist = floor(gross × artistBps / 10000)
 *   fund   = floor(gross × fundBps   / 10000)
 *   ops    = gross − artist − fund   ← absorbs rounding remainder
 *
 * Only EUR is accepted (Bulgaria adopted EUR on 2026-01-01).
 */
export class SettlementCalculator {
  private static readonly CURRENCY = 'EUR';

  calculate(gross: Money, profile: SplitProfile): SettlementResult {
    if (gross.currency !== SettlementCalculator.CURRENCY)
      throw new Error(`Only EUR is accepted for settlement, got: ${gross.currency}`);
    if (gross.isNegative())
      throw new Error(`Gross amount cannot be negative, got: ${gross.cents} cents`);

    const grossCents  = gross.cents;
    const artistCents = Math.floor((grossCents * profile.artistBps) / 10000);
    const fundCents   = Math.floor((grossCents * profile.fundBps)   / 10000);
    const opsCents    = grossCents - artistCents - fundCents;

    const result = new SettlementResult(
      gross,
      Money.fromCents(artistCents, SettlementCalculator.CURRENCY),
      Money.fromCents(fundCents,   SettlementCalculator.CURRENCY),
      Money.fromCents(opsCents,    SettlementCalculator.CURRENCY),
      profile.key,
      profile.version,
      profile.artistBps,
      profile.fundBps,
      profile.opsBps,
    );

    result.assertConservation();
    return result;
  }
}
