import { Money } from './money';

/** Immutable result of SettlementCalculator.calculate(). */
export class SettlementResult {
  constructor(
    public readonly gross:          Money,
    public readonly artist:         Money,
    public readonly fund:           Money,
    public readonly ops:            Money,
    public readonly profileKey:     string,
    public readonly profileVersion: number,
    public readonly artistBps:      number,
    public readonly fundBps:        number,
    public readonly opsBps:         number,
  ) {}

  /** Conservation assertion: artist + fund + ops === gross. Throws if violated. */
  assertConservation(): void {
    const sum = this.artist.cents + this.fund.cents + this.ops.cents;
    if (sum !== this.gross.cents)
      throw new Error(
        `Settlement conservation violated: artist(${this.artist.cents}) + ` +
        `fund(${this.fund.cents}) + ops(${this.ops.cents}) = ${sum}, ` +
        `expected ${this.gross.cents}`,
      );
  }
}
