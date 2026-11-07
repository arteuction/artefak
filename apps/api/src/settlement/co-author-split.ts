import { Money } from './money';

/**
 * Splits the artist pool among co-authors by integer weight.
 * Uses Money.allocate() — largest-remainder, earliest-index bias.
 * Sub-splits live INSIDE artistBps; never touch the fund or ops pools.
 */
export class CoAuthorSplit {
  static split(artistPool: Money, weights: Record<string, number>): Record<string, Money> {
    const keys = Object.keys(weights);
    if (keys.length === 0)
      throw new Error('CoAuthorSplit requires at least one author.');
    const parts = artistPool.allocate(Object.values(weights));
    return Object.fromEntries(keys.map((k, i) => [k, parts[i]]));
  }
}
