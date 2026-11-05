<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Professor operations. Must only be called by routes protected by ProfessorGuard.
 * The scheduling engine remains the authority for capacity and invitation tokens.
 */
final class AdminService
{
    private BaseConnection $db;
    private SchedulingService $scheduling;

    public function __construct(?BaseConnection $database = null)
    {
        $this->db = $database ?? Database::connect('default');
        $this->db->query("SET time_zone = '+00:00'");
        $this->scheduling = new SchedulingService($this->db);
    }

    public function dashboard(): array
    {
        return [
            'studies' => (int) $this->db->table('studies')->countAllResults(),
            'upcoming_sessions' => (int) $this->db->table('sessions')
                ->where('status', 'open')
                ->where('starts_at >', gmdate('Y-m-d H:i:s'))->countAllResults(),
            'invitations' => (int) $this->db->table('invitations')->countAllResults(),
            'active_bookings' => (int) $this->db->table('bookings')
                ->where('status', 'booked')->countAllResults(),
            'notification_pending' => (int) $this->db->table('notification_outbox')
                ->whereIn('status', ['pending', 'sending'])->countAllResults(),
            'notification_failed' => (int) $this->db->table('notification_outbox')
                ->where('status', 'failed')->countAllResults(),
        ];
    }

    public function studies(): array
    {
        return $this->db->query(
            "SELECT st.id, st.title, st.description, st.instructions, st.timezone,
                    st.status, st.created_at, st.updated_at,
                    (SELECT COUNT(*) FROM sessions s WHERE s.study_id = st.id) AS session_count,
                    (SELECT COUNT(*) FROM invitations i WHERE i.study_id = st.id) AS invitation_count,
                    (SELECT COUNT(*) FROM bookings b
                        WHERE b.study_id = st.id AND b.status = 'booked') AS active_bookings
             FROM studies st ORDER BY st.id DESC LIMIT 200"
        )->getResultArray();
    }

    public function updateStudy(int $studyId, array $data): void
    {
        $title = trim((string) ($data['title'] ?? ''));
        $timezone = (string) ($data['timezone'] ?? '');
        $description = (string) ($data['description'] ?? '');
        $instructions = (string) ($data['instructions'] ?? '');

        if ($title === '' || mb_strlen($title) > 200
            || mb_strlen($description) > 20000 || mb_strlen($instructions) > 20000) {
            throw new SchedulingException('INVALID_STUDY');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new SchedulingException('INVALID_TIMEZONE');
        }
        if ($this->db->table('studies')->where('id', $studyId)->countAllResults() !== 1) {
            throw new SchedulingException('STUDY_NOT_FOUND');
        }
        $this->db->table('studies')->where('id', $studyId)->update([
            'title' => $title,
            'timezone' => $timezone,
            'description' => $description,
            'instructions' => $instructions,
        ]);
    }

    /**
     * Parse a datetime-local value in the study's own IANA timezone.
     * Reject spring-forward gaps and fall-back ambiguous times rather than
     * silently booking an appointment an hour away from its stated time.
     */
    public function studyLocalTime(int $studyId, string $value): DateTimeImmutable
    {
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}$/D', $value)) {
            throw new SchedulingException('INVALID_LOCAL_TIME');
        }
        $row = $this->db->query('SELECT timezone FROM studies WHERE id = ?', [
            $studyId,
        ])->getRowArray();
        if ($row === null) {
            throw new SchedulingException('STUDY_NOT_FOUND');
        }
        $tz = new DateTimeZone($row['timezone']);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value, $tz);
        if ($date === false || $date->format('Y-m-d\\TH:i') !== $value) {
            throw new SchedulingException('INVALID_LOCAL_TIME', 'This time does not exist in the study time zone.');
        }

        $wallClockEpoch = (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        $transitions = $tz->getTransitions($date->getTimestamp() - 86400, $date->getTimestamp() + 86400);
        if (is_array($transitions)) {
            for ($i = 1; $i < count($transitions); $i++) {
                $before = $transitions[$i - 1]['offset'];
                $after = $transitions[$i]['offset'];
                $transition = $transitions[$i]['ts'];
                if ($after < $before &&
                    $wallClockEpoch >= $transition + $after &&
                    $wallClockEpoch < $transition + $before) {
                    throw new SchedulingException(
                        'AMBIGUOUS_LOCAL_TIME',
                        'Choose a time outside the daylight-saving clock change.'
                    );
                }
            }
        }
        return $date;
    }

    public function sessions(?int $studyId = null): array
    {
        $condition = $studyId === null ? '' : 'WHERE s.study_id = ?';
        $bindings = $studyId === null ? [] : [$studyId];
        return $this->db->query(
            "SELECT s.id, s.study_id, st.title AS study_title, st.timezone,
                    s.starts_at, s.ends_at, s.location, s.capacity, s.status,
                    (SELECT COUNT(*) FROM bookings b
                        WHERE b.session_id = s.id AND b.status = 'booked') AS booked_seats
             FROM sessions s JOIN studies st ON st.id = s.study_id
             {$condition}
             ORDER BY s.starts_at, s.id LIMIT 500",
            $bindings
        )->getResultArray();
    }

    /**
     * Batch is atomic: a scheduling conflict rolls back every new session.
     *
     * @param list<array{start:DateTimeImmutable,end:DateTimeImmutable,capacity:int,location:string}> $slots
     * @return list<int>
     */
    public function createSessions(int $studyId, array $slots): array
    {
        if ($slots === [] || count($slots) > 30) {
            throw new SchedulingException('INVALID_BATCH', 'Enter between 1 and 30 sessions.');
        }

        return $this->transaction(function () use ($studyId, $slots): array {
            $ids = [];
            foreach ($slots as $slot) {
                $ids[] = $this->scheduling->createSession(
                    $studyId, $slot['start'], $slot['end'],
                    $slot['capacity'], $slot['location']
                );
            }
            return $ids;
        });
    }

    public function setSessionStatus(int $sessionId, string $status): void
    {
        if (!in_array($status, ['open', 'closed', 'canceled'], true)) {
            throw new SchedulingException('INVALID_SESSION_STATUS');
        }

        $this->transaction(function () use ($sessionId, $status): void {
            // Lock globally so reopened sessions cannot overlap newly created sessions.
            $this->db->query('SELECT id FROM schedule_mutex WHERE id = 1 FOR UPDATE');
            $session = $this->db->query(
                'SELECT * FROM sessions WHERE id = ? FOR UPDATE', [$sessionId]
            )->getRowArray();
            if ($session === null) {
                throw new SchedulingException('SESSION_NOT_FOUND');
            }
            if ($status === 'canceled') {
                $booked = (int) $this->db->query(
                    "SELECT COUNT(*) AS n FROM bookings WHERE session_id = ? AND status = 'booked'",
                    [$sessionId]
                )->getRow('n');
                if ($booked !== 0) {
                    throw new SchedulingException(
                        'OCCUPIED_SESSION',
                        'Cancel or move booked participants before canceling this session.'
                    );
                }
            }
            if ($status === 'open') {
                if ($session['starts_at'] <= gmdate('Y-m-d H:i:s')) {
                    throw new SchedulingException('SESSION_STARTED');
                }
                $overlap = $this->db->query(
                    "SELECT id FROM sessions WHERE id <> ? AND status <> 'canceled'
                     AND starts_at < ? AND ends_at > ? LIMIT 1",
                    [$sessionId, $session['ends_at'], $session['starts_at']]
                )->getRowArray();
                if ($overlap !== null) {
                    throw new SchedulingException('SCHEDULE_CONFLICT');
                }
            }
            $this->db->table('sessions')->where('id', $sessionId)
                ->update(['status' => $status]);
        });
    }

    public function invitations(?int $studyId = null): array
    {
        $condition = $studyId === null ? '' : 'WHERE i.study_id = ?';
        $bindings = $studyId === null ? [] : [$studyId];
        // Intentionally never expose token_hash or a reconstructed token.
        return $this->db->query(
            "SELECT i.id, i.study_id, st.title AS study_title,
                    i.participant_email, i.participant_name, i.expires_at,
                    i.revoked_at, i.created_at,
                    b.id AS active_booking_id, b.session_id AS booked_session_id
             FROM invitations i JOIN studies st ON st.id = i.study_id
             LEFT JOIN bookings b ON b.active_invitation_id = i.id AND b.status = 'booked'
             {$condition}
             ORDER BY i.id DESC LIMIT 500",
            $bindings
        )->getResultArray();
    }

    public function bookings(?int $studyId = null): array
    {
        $condition = $studyId === null ? '' : 'WHERE b.study_id = ?';
        $bindings = $studyId === null ? [] : [$studyId];
        return $this->db->query(
            "SELECT b.id, b.study_id, st.title AS study_title,
                    b.session_id, b.invitation_id, b.status,
                    b.created_at, b.updated_at,
                    s.starts_at, s.ends_at, s.location, st.timezone,
                    i.participant_name, i.participant_email
             FROM bookings b
             JOIN studies st ON st.id = b.study_id
             JOIN sessions s ON s.id = b.session_id
             JOIN invitations i ON i.id = b.invitation_id
             {$condition}
             ORDER BY b.created_at DESC, b.id DESC LIMIT 5000",
            $bindings
        )->getResultArray();
    }

    /**
     * Administrative override of participant cutoff. Keep history and release seat.
     */
    public function cancelBooking(int $bookingId): void
    {
        $this->transaction(function () use ($bookingId): void {
            $invitationId = $this->invitationForBooking($bookingId);
            $this->db->query('SELECT id FROM invitations WHERE id = ? FOR UPDATE', [$invitationId]);
            $booking = $this->db->query('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId])->getRowArray();
            if ($booking === null || $booking['status'] !== 'booked') {
                throw new SchedulingException('BOOKING_NOT_ACTIVE');
            }
            $this->db->query('SELECT id FROM sessions WHERE id = ? FOR UPDATE', [(int) $booking['session_id']]);
            $this->db->table('bookings')->where('id', $bookingId)->update([
                'status' => 'canceled',
                'active_invitation_id' => null,
            ]);
            $this->db->table('booking_events')->insert([
                'booking_id' => $bookingId,
                'event_type' => 'canceled',
                'from_session_id' => (int) $booking['session_id'],
                'to_session_id' => null,
            ]);
            (new \App\Notifications\NotificationOutbox($this->db))
                ->enqueueEvent((int) $this->db->insertID());
        });
    }

    /**
     * Administrative reschedule is explicit, atomic and keeps old history.
     */
    public function rescheduleBooking(int $bookingId, int $targetSessionId): int
    {
        return $this->transaction(function () use ($bookingId, $targetSessionId): int {
            $invitationId = $this->invitationForBooking($bookingId);
            $this->db->query('SELECT id FROM invitations WHERE id = ? FOR UPDATE', [$invitationId]);
            $old = $this->db->query('SELECT * FROM bookings WHERE id = ? FOR UPDATE', [$bookingId])->getRowArray();
            if ($old === null || $old['status'] !== 'booked') {
                throw new SchedulingException('BOOKING_NOT_ACTIVE');
            }
            $oldId = (int) $old['session_id'];
            if ($oldId === $targetSessionId) {
                throw new SchedulingException('SAME_SESSION');
            }
            $ids = [$oldId, $targetSessionId];
            sort($ids, SORT_NUMERIC);
            $sessions = $this->db->query(
                'SELECT * FROM sessions WHERE id IN (?, ?) ORDER BY id FOR UPDATE',
                $ids
            )->getResultArray();
            $sessionsById = [];
            foreach ($sessions as $session) {
                $sessionsById[(int) $session['id']] = $session;
            }
            if (!isset($sessionsById[$oldId], $sessionsById[$targetSessionId])) {
                throw new SchedulingException('SESSION_NOT_FOUND');
            }
            $target = $sessionsById[$targetSessionId];
            if ((int) $target['study_id'] !== (int) $old['study_id']) {
                throw new SchedulingException('WRONG_STUDY');
            }
            $study = $this->db->query('SELECT status FROM studies WHERE id = ?', [
                (int) $old['study_id'],
            ])->getRow('status');
            if ($target['status'] !== 'open' || $study !== 'active'
                || $target['starts_at'] <= gmdate('Y-m-d H:i:s')) {
                throw new SchedulingException('SESSION_UNAVAILABLE');
            }
            $occupied = (int) $this->db->query(
                "SELECT COUNT(*) AS n FROM bookings WHERE session_id = ? AND status = 'booked'",
                [$targetSessionId]
            )->getRow('n');
            if ($occupied >= (int) $target['capacity']) {
                throw new SchedulingException('SESSION_FULL');
            }

            $this->db->table('bookings')->where('id', $bookingId)->update([
                'status' => 'rescheduled',
                'active_invitation_id' => null,
            ]);
            $this->db->table('bookings')->insert([
                'invitation_id' => $invitationId,
                'study_id' => (int) $old['study_id'],
                'session_id' => $targetSessionId,
                'active_invitation_id' => $invitationId,
                'status' => 'booked',
            ]);
            $newBookingId = (int) $this->db->insertID();
            $this->db->table('booking_events')->insert([
                'booking_id' => $newBookingId,
                'event_type' => 'rescheduled',
                'from_session_id' => $oldId,
                'to_session_id' => $targetSessionId,
            ]);
            (new \App\Notifications\NotificationOutbox($this->db))
                ->enqueueEvent((int) $this->db->insertID());
            return $newBookingId;
        });
    }

    private function invitationForBooking(int $bookingId): int
    {
        $row = $this->db->query(
            'SELECT invitation_id FROM bookings WHERE id = ?', [$bookingId]
        )->getRowArray();
        if ($row === null) {
            throw new SchedulingException('BOOKING_NOT_FOUND');
        }
        return (int) $row['invitation_id'];
    }

    private function transaction(callable $work): mixed
    {
        $this->db->transException(true);
        $this->db->transBegin();
        try {
            $result = $work();
            if ($this->db->transStatus() === false) {
                throw new SchedulingException('DATABASE_TRANSACTION_FAILED');
            }
            $this->db->transCommit();
            return $result;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }
}
