/**
 * Phase 0.3U — PayoutService unit tests
 *
 * PS1  artistPayouts — default page/perPage
 * PS2  artistPayouts — custom page/perPage
 * PS3  galleryPayouts — default
 * PS4  galleryPayouts — custom page/perPage
 */

import { Test, TestingModule } from '@nestjs/testing';
import { PayoutService } from './payout.service';

describe('Phase 0.3U — PayoutService', () => {
  let svc: PayoutService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [PayoutService],
    }).compile();
    svc = module.get(PayoutService);
  });

  it('PS1: artistPayouts default pagination', async () => {
    const result = await svc.artistPayouts(1);
    expect(result).toHaveProperty('data');
    expect(result).toHaveProperty('meta');
    expect(result.meta.current_page).toBe(1);
    expect(result.meta.per_page).toBe(20);
  });

  it('PS2: artistPayouts custom page/perPage', async () => {
    const result = await svc.artistPayouts(1, 3, 5);
    expect(result.meta.current_page).toBe(3);
    expect(result.meta.per_page).toBe(5);
  });

  it('PS3: galleryPayouts default pagination', async () => {
    const result = await svc.galleryPayouts(10);
    expect(result).toHaveProperty('data');
    expect(result.meta.current_page).toBe(1);
  });

  it('PS4: galleryPayouts custom page/perPage', async () => {
    const result = await svc.galleryPayouts(10, 2, 10);
    expect(result.meta.current_page).toBe(2);
    expect(result.meta.per_page).toBe(10);
  });
});
