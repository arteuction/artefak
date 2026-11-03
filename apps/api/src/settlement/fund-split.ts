import { Money } from './money';

/**
 * Splits the social fund pool among NGO recipients by integer weight.
 * Sub-splits live INSIDE fundBps (per Settlement Kernel Spec).
 * Never touches artist or ops pools.
 */
export class FundSplit {
  static split(fundPool: Money, weights: Record<string, number>): Record<string, Money> {
    const keys = Object.keys(weights);
    if (keys.length === 0)
      throw new Error('FundSplit requires at least one NGO recipient.');
    const parts = fundPool.allocate(Object.values(weights));
    return Object.fromEntries(keys.map((k, i) => [k, parts[i]]));
  }
}
