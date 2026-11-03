-- Migration: add_outbox_lease_columns
-- Phase 0.7.2 — Multi-instance reliability
-- All timestamps use UTC (MariaDB UTC_TIMESTAMP convention, consistent with existing schema)

-- domain_events: lease_expires_at for multi-instance atomic claiming
ALTER TABLE `domain_events`
  ADD COLUMN `lease_expires_at` DATETIME NULL AFTER `processing_started_at`;

-- Index for the stale-claim recovery query: WHERE status='processing' AND lease_expires_at < NOW()
CREATE INDEX `de_lease_recovery_idx`
  ON `domain_events` (`status`, `lease_expires_at`);

-- transfer_outbox: lease_expires_at for multi-instance atomic claiming
ALTER TABLE `transfer_outbox`
  ADD COLUMN `lease_expires_at` DATETIME NULL AFTER `processing_started_at`;

-- transfer_outbox: external_ref for stable Stripe reconciliation
-- Generated once when the row is created; passed in Stripe transfer metadata.
-- Allows reconciliation even when stripe_transfer_id was not yet persisted.
ALTER TABLE `transfer_outbox`
  ADD COLUMN `external_ref` VARCHAR(64) NULL AFTER `stripe_transfer_id`;

-- Index for lease recovery on transfer_outbox
CREATE INDEX `to_lease_recovery_idx`
  ON `transfer_outbox` (`status`, `lease_expires_at`);

-- Index for pending-event selection (status + next_attempt_at already has de_dispatch_queue_idx;
-- this adds the transfer_outbox equivalent explicitly)
CREATE INDEX `to_dispatch_queue_idx`
  ON `transfer_outbox` (`status`, `next_attempt_at`);

-- Unique index on external_ref — prevents accidental duplicate rows with same ref
CREATE UNIQUE INDEX `to_external_ref_unique`
  ON `transfer_outbox` (`external_ref`);
