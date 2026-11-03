/**
 * Money value object — integer cents only, currency-tagged, no float arithmetic.
 * Mirrors PHP Money exactly: allocate() uses largest-remainder, earliest-index bias.
 */
export class Money {
  private constructor(
    public readonly cents: number,
    public readonly currency: string,
  ) {}

  static fromCents(cents: number, currency = 'EUR'): Money {
    if (!Number.isInteger(cents)) throw new Error(`cents must be integer, got ${cents}`);
    return new Money(cents, currency);
  }

  plus(other: Money): Money {
    this.assertSame(other);
    return new Money(this.cents + other.cents, this.currency);
  }

  minus(other: Money): Money {
    this.assertSame(other);
    return new Money(this.cents - other.cents, this.currency);
  }

  equals(other: Money): boolean {
    return this.cents === other.cents && this.currency === other.currency;
  }

  isNegative(): boolean {
    return this.cents < 0;
  }

  /**
   * Split into parts proportional to weights, losing no cents.
   * Largest-remainder method; ties break toward earliest index.
   * Parts always sum exactly to this.cents.
   */
  allocate(weights: number[]): Money[] {
    const sumW = weights.reduce((a, b) => a + b, 0);
    if (sumW === 0) return weights.map(() => new Money(0, this.currency));

    const floors: number[] = [];
    const remainders: number[] = [];

    for (const w of weights) {
      const exact = (this.cents * w) / sumW;
      floors.push(Math.floor(exact));
      remainders.push(exact - Math.floor(exact));
    }

    let leftover = this.cents - floors.reduce((a, b) => a + b, 0);

    const indices = weights.map((_, i) => i);
    indices.sort((a, b) => {
      const diff = remainders[b] - remainders[a];
      if (Math.abs(diff) > 1e-12) return diff > 0 ? 1 : -1;
      return a - b; // tie-break: earliest index
    });

    const result = [...floors];
    for (let i = 0; i < leftover; i++) result[indices[i]]++;

    return result.map((c) => new Money(c, this.currency));
  }

  private assertSame(other: Money): void {
    if (this.currency !== other.currency)
      throw new Error(`Currency mismatch: ${this.currency} vs ${other.currency}`);
  }
}
