<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Core research scheduling schema, targeting MySQL 8 and MariaDB 10.6+.
 *
 * All sessions are for one professor. An explicit schedule_mutex row lets
 * session-creation services serialize the overlap check across all studies.
 * Bookings use a nullable active_invitation_id unique key because MySQL
 * does not support partial unique indexes.
 */
final class CreateSchedulingSchema extends Migration
{
    public function up(): void
    {
        $this->db->query(<<<'SQL'
CREATE TABLE studies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    instructions TEXT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Chicago',
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    CONSTRAINT ck_study_status CHECK (status IN ('draft','active','closed','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    study_id BIGINT UNSIGNED NOT NULL,
    starts_at DATETIME(6) NOT NULL,
    ends_at DATETIME(6) NOT NULL,
    location VARCHAR(300) NOT NULL DEFAULT '',
    capacity INT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_session_study (id, study_id),
    KEY ix_session_schedule (starts_at, ends_at, status),
    KEY ix_session_study_start (study_id, starts_at),
    CONSTRAINT fk_session_study FOREIGN KEY (study_id) REFERENCES studies(id),
    CONSTRAINT ck_session_times CHECK (ends_at > starts_at),
    CONSTRAINT ck_session_capacity CHECK (capacity >= 1),
    CONSTRAINT ck_session_status CHECK (status IN ('open','closed','canceled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE invitations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    study_id BIGINT UNSIGNED NOT NULL,
    participant_email VARCHAR(254) NOT NULL,
    participant_name VARCHAR(200) NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME(6) NULL,
    revoked_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_invitation_study (id, study_id),
    UNIQUE KEY uq_invitation_token_hash (token_hash),
    KEY ix_invitation_study (study_id),
    CONSTRAINT fk_invitation_study FOREIGN KEY (study_id) REFERENCES studies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invitation_id BIGINT UNSIGNED NOT NULL,
    study_id BIGINT UNSIGNED NOT NULL,
    session_id BIGINT UNSIGNED NOT NULL,
    active_invitation_id BIGINT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'booked',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_one_active_booking_per_invitation (active_invitation_id),
    KEY ix_booking_session_status (session_id, status),
    KEY ix_booking_invitation_status (invitation_id, status),
    CONSTRAINT fk_booking_invitation_study FOREIGN KEY (invitation_id, study_id)
        REFERENCES invitations(id, study_id),
    CONSTRAINT fk_booking_session_study FOREIGN KEY (session_id, study_id)
        REFERENCES sessions(id, study_id),
    CONSTRAINT fk_booking_active_invitation FOREIGN KEY (active_invitation_id)
        REFERENCES invitations(id),
    CONSTRAINT ck_booking_state CHECK (
        (status = 'booked' AND active_invitation_id = invitation_id)
        OR (status IN ('canceled','rescheduled') AND active_invitation_id IS NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE booking_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(16) NOT NULL,
    from_session_id BIGINT UNSIGNED NULL,
    to_session_id BIGINT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY ix_booking_event_booking (booking_id, id),
    CONSTRAINT fk_event_booking FOREIGN KEY (booking_id) REFERENCES bookings(id),
    CONSTRAINT ck_booking_event CHECK (event_type IN ('booked','canceled','rescheduled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        $this->db->query(<<<'SQL'
CREATE TABLE schedule_mutex (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $this->db->query('INSERT INTO schedule_mutex (id) VALUES (1)');
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS booking_events');
        $this->db->query('DROP TABLE IF EXISTS bookings');
        $this->db->query('DROP TABLE IF EXISTS invitations');
        $this->db->query('DROP TABLE IF EXISTS sessions');
        $this->db->query('DROP TABLE IF EXISTS studies');
        $this->db->query('DROP TABLE IF EXISTS schedule_mutex');
    }
}
