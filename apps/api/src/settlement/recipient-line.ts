import { Money } from './money';

export type RecipientType = 'artist' | 'gallery' | 'fund' | 'platform' | 'ngo';

export interface RecipientLine {
  type:            RecipientType;
  legalEntityId:   number | null;
  userId:          number | null;
  entityName:      string | null;
  entityEik:       string | null;
  entityRole:      string | null;
  stripeAccountId: string | null;
  amount:          Money;
  weight:          number;
}
