/**
 * Phase 0.3U â€” VenueService unit tests
 *
 * VS1  listVenues default pagination
 * VS2  listVenues custom pagination â€” last_page calculation
 * VS3  getVenue by slug â€” found
 * VS4  getVenue by slug â€” not found: throws NotFoundException
 * VS5  getVenue by numeric id â€” found
 * VS6  getVenueMap â€” returns array of pin records
 * VS7  createVenue â€” delegates to prisma.create
 * VS8  updateVenue â€” delegates via getVenue then prisma.update
 * VS9  updateVenue â€” venue not found: throws NotFoundException
 */

import { jest } from '@jest/globals';
import { Test, TestingModule } from '@nestjs/testing';
import { NotFoundException } from '@nestjs/common';
import { VenueService } from './venue.service';
import { PrismaService } from '../prisma.service';

const STUB_VENUE = {
  id: BigInt(1), name: 'Sofia Art Center', slug: 'sofia-art-center',
  address: 'ul. Rakovski 125', latitude: 42.6977, longitude: 23.3219,
  is_active: true, created_at: new Date(),
};

const fn = () => jest.fn() as any;
const mockPrisma = {
  venues: { findMany: fn(), findFirst: fn(), create: fn(), update: fn(), count: fn() },
  $transaction: fn(),
};

describe('Phase 0.3U â€” VenueService', () => {
  let svc: VenueService;

  beforeAll(async () => {
    const module: TestingModule = await Test.createTestingModule({
      providers: [
        VenueService,
        { provide: PrismaService, useValue: mockPrisma },
      ],
    }).compile();
    svc = module.get(VenueService);
  });

  beforeEach(() => { jest.clearAllMocks(); });

  it('VS1: listVenues default pagination', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[STUB_VENUE], 1]);
    const result = await svc.listVenues();
    expect(result.data).toHaveLength(1);
    expect(result.meta.current_page).toBe(1);
    expect(result.meta.per_page).toBe(20);
    expect(result.meta.total).toBe(1);
    expect(result.meta.last_page).toBe(1);
  });

  it('VS2: listVenues â€” last_page calculation with 45 items at perPage=20', async () => {
    mockPrisma.$transaction.mockResolvedValueOnce([[], 45]);
    const result = await svc.listVenues(1, 20);
    expect(result.meta.last_page).toBe(3);
  });

  it('VS3: getVenue by slug â€” found', async () => {
    mockPrisma.venues.findFirst.mockResolvedValueOnce(STUB_VENUE);
    const result = await svc.getVenue('sofia-art-center');
    expect(result.slug).toBe('sofia-art-center');
  });

  it('VS4: getVenue by slug â€” not found: throws NotFoundException', async () => {
    mockPrisma.venues.findFirst.mockResolvedValueOnce(null);
    await expect(svc.getVenue('missing')).rejects.toThrow(NotFoundException);
  });

  it('VS5: getVenue by numeric id â€” found', async () => {
    mockPrisma.venues.findFirst.mockResolvedValueOnce(STUB_VENUE);
    const result = await svc.getVenue('1');
    expect(result.id).toEqual(BigInt(1));
  });

  it('VS6: getVenueMap â€” returns array of pins', async () => {
    const PINS = [{ id: BigInt(1), name: 'Sofia Art Center', latitude: 42.6977, longitude: 23.3219, slug: 'sofia-art-center' }];
    mockPrisma.venues.findMany.mockResolvedValueOnce(PINS);
    const result = await svc.getVenueMap();
    expect(result).toEqual(PINS);
  });

  it('VS7: createVenue â€” delegates to prisma.create', async () => {
    mockPrisma.venues.create.mockResolvedValueOnce(STUB_VENUE);
    const result = await svc.createVenue({ name: 'New Venue', slug: 'new-venue' });
    expect(result).toEqual(STUB_VENUE);
    expect(mockPrisma.venues.create).toHaveBeenCalled();
  });

  it('VS8: updateVenue â€” delegates via getVenue then prisma.update', async () => {
    mockPrisma.venues.findFirst.mockResolvedValueOnce(STUB_VENUE);
    mockPrisma.venues.update.mockResolvedValueOnce({ ...STUB_VENUE, name: 'Updated' });
    const result = await svc.updateVenue('sofia-art-center', { name: 'Updated' });
    expect(result.name).toBe('Updated');
    expect(mockPrisma.venues.update).toHaveBeenCalled();
  });

  it('VS9: updateVenue â€” venue not found: throws NotFoundException', async () => {
    mockPrisma.venues.findFirst.mockResolvedValueOnce(null);
    await expect(svc.updateVenue('missing', { name: 'X' })).rejects.toThrow(NotFoundException);
  });
});
