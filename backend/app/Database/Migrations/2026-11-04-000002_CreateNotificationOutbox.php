<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Transactional email outbox. Mail is enqueued in the same transaction as
 * booking_events and sent only after that transaction has committed.
 */
final class CreateNotificationOutbox extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
CREATE TABLE notification_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_event_id BIGINT UNSIGNED NOT NULL,
    recipient_role VARCHAR(16) NOT NULL,
    recipient_email VARCHAR(254) NULL,
    payload_json JSON NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    claimed_at DATETIME(6) NULL,
    claim_key CHAR(32) NULL,
    sent_at DATETIME(6) NULL,
    last_error_code VARCHAR(40) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_notification_event_recipient (booking_event_id, recipient_role),
    KEY ix_notification_due (status, next_attempt_at, id),
    CONSTRAINT fk_notification_booking_event FOREIGN KEY (booking_event_id)
        REFERENCES booking_events(id),
    CONSTRAINT ck_notification_role CHECK (recipient_role IN ('participant', 'professor')),
    CONSTRAINT ck_notification_status CHECK (status IN ('pending', 'sending', 'sent', 'failed')),
    CONSTRAINT ck_notification_attempts CHECK (attempts <= 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS notification_outbox');
    }
}
