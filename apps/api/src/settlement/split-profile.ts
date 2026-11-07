/**
 * Canonical revenue-split profile in basis points (1/100 of a percent).
 * artistBps + fundBps + opsBps MUST equal 10000.
 *
 * Mirrors PHP SplitProfile exactly. Unknown keys throw.
 */
const PROFILES: Record<string, { artist: number; fund: number; ops: number }> = {
  social_pilot_45_45_10: { artist: 4500, fund: 4500, ops: 1000 },
  library_80_10_10:      { artist: 8000, fund: 1000, ops: 1000 },
};

export const SPLIT_PROFILE_VERSION = 1;

export class SplitProfile {
  private constructor(
    private readonly _key: string,
    private readonly _artistBps: number,
    private readonly _fundBps: number,
    private readonly _opsBps: number,
  ) {}

  static fromKey(key: string): SplitProfile {
    const p = PROFILES[key];
    if (!p) {
      const known = Object.keys(PROFILES).join("', '");
      throw new Error(`Unknown split profile '${key}'. Known: '${known}'.`);
    }
    const sum = p.artist + p.fund + p.ops;
    if (sum !== 10000)
      throw new Error(`Split profile '${key}' bps sum to ${sum}, expected 10000.`);
    return new SplitProfile(key, p.artist, p.fund, p.ops);
  }

  get key(): string        { return this._key; }
  get version(): number    { return SPLIT_PROFILE_VERSION; }
  get artistBps(): number  { return this._artistBps; }
  get fundBps(): number    { return this._fundBps; }
  get opsBps(): number     { return this._opsBps; }
}
