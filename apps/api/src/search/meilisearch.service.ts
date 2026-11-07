import { Injectable, Logger } from '@nestjs/common';
import { Meilisearch } from 'meilisearch';

export const ARTWORKS_INDEX = 'artworks';

export interface ArtworkDocument {
  id: number;
  title: string;
  slug: string;
  medium: string | null;
  year_created: number | null;
  status: string;
}

@Injectable()
export class MeilisearchService {
  private readonly logger = new Logger(MeilisearchService.name);
  private readonly client: Meilisearch | null;
  readonly enabled: boolean;

  constructor() {
    const host = process.env.MEILISEARCH_HOST;
    const apiKey = process.env.MEILISEARCH_KEY ?? '';
    this.enabled = !!host;

    this.client = this.enabled
      ? new Meilisearch({ host: host!, apiKey })
      : null;
  }

  async searchArtworks(query: string, limit = 20, offset = 0): Promise<{ hits: ArtworkDocument[]; estimatedTotalHits: number }> {
    if (!this.client) throw new Error('Meilisearch not configured');

    const result = await this.client.index(ARTWORKS_INDEX).search<ArtworkDocument>(query, {
      limit,
      offset,
      attributesToRetrieve: ['id', 'title', 'slug', 'medium', 'year_created', 'status'],
    });

    return {
      hits: result.hits,
      estimatedTotalHits: result.estimatedTotalHits ?? result.hits.length,
    };
  }

  async indexArtwork(doc: ArtworkDocument): Promise<void> {
    if (!this.client) return;
    await this.client.index(ARTWORKS_INDEX).addDocuments([doc]);
  }

  async deleteArtwork(id: number): Promise<void> {
    if (!this.client) return;
    await this.client.index(ARTWORKS_INDEX).deleteDocument(id);
  }

  async ensureIndex(): Promise<void> {
    if (!this.client) return;
    try {
      await this.client.createIndex(ARTWORKS_INDEX, { primaryKey: 'id' });
      await this.client.index(ARTWORKS_INDEX).updateSettings({
        searchableAttributes: ['title', 'medium'],
        filterableAttributes: ['status', 'year_created'],
        sortableAttributes: ['year_created'],
      });
    } catch {
      // Index already exists — ignore
    }
  }
}
