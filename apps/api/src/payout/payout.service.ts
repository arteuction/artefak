import { Injectable } from '@nestjs/common';

const emptyPaged = (page: number, perPage: number) => ({
  data: [],
  meta: { current_page: page, last_page: 1, per_page: perPage, total: 0 },
});

@Injectable()
export class PayoutService {
  async artistPayouts(_userId: number, page = 1, perPage = 20) {
    return emptyPaged(page, perPage);
  }

  async galleryPayouts(_galleryId: number, page = 1, perPage = 20) {
    return emptyPaged(page, perPage);
  }
}
