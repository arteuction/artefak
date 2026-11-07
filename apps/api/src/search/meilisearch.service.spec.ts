/**
 * Phase 0.6C — MeilisearchService unit tests
 *
 * MS1  disabled (no MEILISEARCH_HOST): enabled is false
 * MS2  disabled: indexArtwork is a no-op (does not throw)
 * MS3  disabled: deleteArtwork is a no-op
 * MS4  disabled: ensureIndex is a no-op
 * MS5  disabled: searchArtworks throws 'Meilisearch not configured'
 * MS6  enabled (mocked client): searchArtworks returns hits + estimatedTotalHits
 * MS7  enabled: indexArtwork calls addDocuments with the document
 * MS8  enabled: deleteArtwork calls deleteDocument with numeric id
 * MS9  enabled: ensureIndex calls createIndex and updateSettings
 * MS10 enabled: ensureIndex swallows error when index already exists
 */

import { jest } from '@jest/globals';
import { MeilisearchService, ARTWORKS_INDEX } from './meilisearch.service';

describe('Phase 0.6C — MeilisearchService', () => {
  describe('disabled (no MEILISEARCH_HOST)', () => {
    let svc: MeilisearchService;

    beforeAll(() => {
      delete process.env.MEILISEARCH_HOST;
      svc = new MeilisearchService();
    });

    it('MS1: enabled is false', () => {
      expect(svc.enabled).toBe(false);
    });

    it('MS2: indexArtwork is a no-op', async () => {
      await expect(svc.indexArtwork({ id: 1, title: 'T', slug: 's', medium: null, year_created: null, status: 'published' })).resolves.toBeUndefined();
    });

    it('MS3: deleteArtwork is a no-op', async () => {
      await expect(svc.deleteArtwork(1)).resolves.toBeUndefined();
    });

    it('MS4: ensureIndex is a no-op', async () => {
      await expect(svc.ensureIndex()).resolves.toBeUndefined();
    });

    it('MS5: searchArtworks throws when not configured', async () => {
      await expect(svc.searchArtworks('painting')).rejects.toThrow('Meilisearch not configured');
    });
  });

  describe('enabled (mocked meilisearch client)', () => {
    let svc: MeilisearchService;

    const mockIndex = {
      search: jest.fn(async () => ({ hits: [{ id: 1, title: 'Blue', slug: 'blue', medium: 'oil', year_created: 2020, status: 'published' }], estimatedTotalHits: 1 })) as any,
      addDocuments: jest.fn(async () => ({ taskUid: 1 })) as any,
      deleteDocument: jest.fn(async () => ({ taskUid: 2 })) as any,
      updateSettings: jest.fn(async () => ({ taskUid: 3 })) as any,
    };

    const mockMeiliClient = {
      index: jest.fn(() => mockIndex) as any,
      createIndex: jest.fn(async () => ({ taskUid: 4 })) as any,
    };

    beforeAll(() => {
      process.env.MEILISEARCH_HOST = 'http://localhost:7700';
      svc = new MeilisearchService();
      (svc as any).client = mockMeiliClient;
    });

    afterAll(() => {
      delete process.env.MEILISEARCH_HOST;
    });

    beforeEach(() => { jest.resetAllMocks(); });

    it('MS6: searchArtworks returns hits and estimatedTotalHits', async () => {
      mockMeiliClient.index.mockReturnValueOnce(mockIndex);
      mockIndex.search.mockResolvedValueOnce({
        hits: [{ id: 1, title: 'Blue', slug: 'blue', medium: 'oil', year_created: 2020, status: 'published' }],
        estimatedTotalHits: 42,
      });
      const result = await svc.searchArtworks('blue', 10, 0);
      expect(result.hits).toHaveLength(1);
      expect(result.estimatedTotalHits).toBe(42);
      expect(mockMeiliClient.index).toHaveBeenCalledWith(ARTWORKS_INDEX);
    });

    it('MS7: indexArtwork calls addDocuments with the artwork document', async () => {
      mockMeiliClient.index.mockReturnValueOnce(mockIndex);
      mockIndex.addDocuments.mockResolvedValueOnce({ taskUid: 1 });
      const doc = { id: 5, title: 'Sunset', slug: 'sunset', medium: 'acrylic', year_created: 2022, status: 'published' };
      await svc.indexArtwork(doc);
      expect(mockIndex.addDocuments).toHaveBeenCalledWith([doc]);
    });

    it('MS8: deleteArtwork calls deleteDocument with numeric id', async () => {
      mockMeiliClient.index.mockReturnValueOnce(mockIndex);
      mockIndex.deleteDocument.mockResolvedValueOnce({ taskUid: 2 });
      await svc.deleteArtwork(7);
      expect(mockIndex.deleteDocument).toHaveBeenCalledWith(7);
    });

    it('MS9: ensureIndex calls createIndex and updateSettings', async () => {
      mockMeiliClient.createIndex.mockResolvedValueOnce({ taskUid: 4 });
      mockMeiliClient.index.mockReturnValueOnce(mockIndex);
      mockIndex.updateSettings.mockResolvedValueOnce({ taskUid: 3 });
      await svc.ensureIndex();
      expect(mockMeiliClient.createIndex).toHaveBeenCalledWith(ARTWORKS_INDEX, { primaryKey: 'id' });
      expect(mockIndex.updateSettings).toHaveBeenCalledWith(
        expect.objectContaining({ searchableAttributes: expect.arrayContaining(['title']) })
      );
    });

    it('MS10: ensureIndex swallows error when index already exists', async () => {
      mockMeiliClient.createIndex.mockRejectedValueOnce(new Error('index_already_exists'));
      await expect(svc.ensureIndex()).resolves.not.toThrow();
    });
  });
});
