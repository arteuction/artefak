/**
 * Phase 0.5 — WatchlistController
 *
 * WL1  GET /watchlist — returns entries for authenticated user
 * WL2  POST /watchlist — adds entry and returns it
 * WL3  DELETE /watchlist/:id — removes entry, returns {deleted:true}
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { WatchlistController } from './watchlist.controller';
import { ProfileService } from './profile.service';

const fn = () => jest.fn() as any;

const mockProfile = {
  getWatchlist:        fn(),
  addToWatchlist:      fn(),
  removeFromWatchlist: fn(),
};

const makeReq = (sub = 42) => ({ user: { sub } });

describe('Phase 0.5 — WatchlistController', () => {
  let ctrl: WatchlistController;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      controllers: [WatchlistController],
      providers: [{ provide: ProfileService, useValue: mockProfile }],
    }).compile();
    ctrl = module.get(WatchlistController);
  });

  beforeEach(() => { jest.resetAllMocks(); });

  it('WL1: list — returns watchlist entries', async () => {
    const entries = [{ id: BigInt(1), subject_type: 'artwork', subject_id: BigInt(5) }];
    mockProfile.getWatchlist.mockResolvedValueOnce(entries);
    const result = await ctrl.list(makeReq());
    expect(mockProfile.getWatchlist).toHaveBeenCalledWith(42);
    expect(result).toEqual(entries);
  });

  it('WL2: add — upserts and returns entry', async () => {
    const entry = { id: BigInt(2), subject_type: 'auction', subject_id: BigInt(3) };
    mockProfile.addToWatchlist.mockResolvedValueOnce(entry);
    const result = await ctrl.add(makeReq(), { subject_type: 'auction', subject_id: 3 });
    expect(mockProfile.addToWatchlist).toHaveBeenCalledWith(42, 'auction', 3);
    expect(result).toEqual(entry);
  });

  it('WL3: remove — deletes entry, returns {deleted:true}', async () => {
    mockProfile.removeFromWatchlist.mockResolvedValueOnce({ deleted: true });
    const result = await ctrl.remove(makeReq(), 7);
    expect(mockProfile.removeFromWatchlist).toHaveBeenCalledWith(42, 7);
    expect(result).toEqual({ deleted: true });
  });
});
