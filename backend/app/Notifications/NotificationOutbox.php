<?php

namespace App\Notifications;

use CodeIgniter\Database\BaseConnection;
use JsonException;

/**
 * Called INSIDE a successful booking transaction. Never connects to SMTP.
 * A failed outbox insert therefore rolls the booking back rather than losing
 * its corresponding event. The token hash and raw invite link are never copied.
 */
final class NotificationOutbox
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function enqueueEvent(int $eventId): void
    {
        $row = $this->db->query(
            "SELECT e.id, e.event_type,
                    st.title AS study_title, st.timezone,
                    i.participant_name, i.participant_email,
                    newer.starts_at, newer.ends_at, newer.location,
                    older.starts_at AS previous_starts_at,
                    older.ends_at AS previous_ends_at,
                    older.location AS previous_location
             FROM booking_events e
             JOIN bookings b ON b.id = e.booking_id
             JOIN invitations i ON i.id = b.invitation_id
             JOIN studies st ON st.id = b.study_id
             LEFT JOIN sessions newer ON newer.id = e.to_session_id
             LEFT JOIN sessions older ON older.id = e.from_session_id
             WHERE e.id = ?",
            [$eventId]
        )->getRowArray();

        if ($row === null) {
            throw new \RuntimeException('Notification source event not found.');
        }

        $payload = [
            'kind' => $row['event_type'],
            'study_title' => $row['study_title'],
            'timezone' => $row['timezone'],
            'participant_name' => $row['participant_name'],
            'participant_email' => $row['participant_email'],
            'starts_at' => $row['starts_at'],
            'ends_at' => $row['ends_at'],
            'location' => $row['location'],
            'previous_starts_at' => $row['previous_starts_at'],
            'previous_ends_at' => $row['previous_ends_at'],
            'previous_location' => $row['previous_location'],
        ];

        $this->queue($eventId, 'participant', (string) $row['participant_email'], $payload);

        // Optional second, independently retried message. The professor's
        // address is never a required paid service or third-party dependency.
        $professorEmail = trim((string) env('notifications.professorCopyEmail', ''));
        if ($professorEmail !== '') {
            if (!filter_var($professorEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Professor notification address is invalid.');
            }
            if (strcasecmp($professorEmail, (string) $row['participant_email']) !== 0) {
                $this->queue($eventId, 'professor', $professorEmail, $payload);
            }
        }
    }

    private function queue(int $eventId, string $role, string $address, array $payload): void
    {
        $this->db->table('notification_outbox')->insert([
            'booking_event_id' => $eventId,
            'recipient_role' => $role,
            'recipient_email' => $address,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'attempts' => 0,
        ]);
    }
}
