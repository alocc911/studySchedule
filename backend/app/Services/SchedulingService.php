<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Internal domain service: not an HTTP controller.
 *
 * Controllers added in later phases must authenticate the professor before
 * calling administrative methods and authorize invite-token requests.
 * Every mutating booking operation uses one MySQL transaction.
 */
final class SchedulingService
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $database = null, private readonly int $changeCutoffHours = 24)
    {
        if ($changeCutoffHours < 0) {
            throw new \InvalidArgumentException('Change cutoff must not be negative.');
        }

        $this->db = $database ?? Database::connect('default');
        $this->db->query("SET time_zone = '+00:00'");
    }

    /**
     * A draft study cannot accept participant bookings until activated.
     */
    public function createStudy(
        string $title,
        string $timezone = 'America/Chicago',
        string $description = '',
        string $instructions = ''
    ): int {
        $title = trim($title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw new SchedulingException('INVALID_STUDY', 'A study title is required (maximum 200 characters).');
        }
        if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            throw new SchedulingException('INVALID_TIMEZONE', 'Choose a valid IANA time zone.');
        }
        $this->db->table('studies')->insert([
            'title' => $title,
            'timezone' => $timezone,
            'description' => $description,
            'instructions' => $instructions,
            'status' => 'draft',
        ]);
        return (int) $this->db->insertID();
    }

    public function setStudyStatus(int $studyId, string $status): void
    {
        if (!in_array($status, ['draft', 'active', 'closed', 'archived'], true)) {
            throw new SchedulingException('INVALID_STUDY_STATUS');
        }
        if ($this->db->table('studies')->where('id', $studyId)->countAllResults() !== 1) {
            throw new SchedulingException('STUDY_NOT_FOUND');
        }
        $this->db->table('studies')->where('id', $studyId)->update(['status' => $status]);
    }

    /**
     * Session is the physical meeting: capacity 1 = individual, >1 = group.
     * The same professor cannot host simultaneous sessions, even between studies.
     */
    public function createSession(
        int $studyId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        int $capacity,
        string $location = ''
    ): int {
        $startsAt = self::utc($start);
        $endsAt = self::utc($end);
        if ($endsAt <= $startsAt || $startsAt <= self::now()) {
            throw new SchedulingException('INVALID_SESSION_TIME', 'Session must start in the future and end after it begins.');
        }
        if ($capacity < 1 || $capacity > 1000) {
            throw new SchedulingException('INVALID_CAPACITY');
        }
        if (mb_strlen($location) > 300) {
            throw new SchedulingException('INVALID_LOCATION');
        }

        return $this->transaction(function () use ($studyId, $startsAt, $endsAt, $capacity, $location): int {
            // All session creation paths must lock this one row. No overlapping
            // sessions can pass this check concurrently, across all studies.
            $this->db->query('SELECT id FROM schedule_mutex WHERE id = 1 FOR UPDATE');
            $study = $this->db->query('SELECT id FROM studies WHERE id = ? FOR UPDATE', [$studyId])->getRowArray();
            if ($study === null) {
                throw new SchedulingException('STUDY_NOT_FOUND');
            }
            $conflict = $this->db->query(
                "SELECT id FROM sessions
                 WHERE status <> 'canceled' AND starts_at < ? AND ends_at > ? LIMIT 1",
                [self::format($endsAt), self::format($startsAt)]
            )->getRowArray();
            if ($conflict !== null) {
                throw new SchedulingException('SCHEDULE_CONFLICT', 'Another session already overlaps these times.');
            }

            $this->db->table('sessions')->insert([
                'study_id' => $studyId,
                'starts_at' => self::format($startsAt),
                'ends_at' => self::format($endsAt),
                'capacity' => $capacity,
                'location' => trim($location),
                'status' => 'open',
            ]);
            return (int) $this->db->insertID();
        });
    }

    public function changeSessionCapacity(int $sessionId, int $newCapacity): void
    {
        if ($newCapacity < 1 || $newCapacity > 1000) {
            throw new SchedulingException('INVALID_CAPACITY');
        }
        $this->transaction(function () use ($sessionId, $newCapacity): void {
            $session = $this->lockSession($sessionId);
            if ($newCapacity < $this->reservedSeats($sessionId)) {
                throw new SchedulingException('CAPACITY_BELOW_BOOKINGS');
            }
            $this->db->table('sessions')->where('id', (int) $session['id'])->update([
                'capacity' => $newCapacity,
            ]);
        });
    }

    /**
     * Raw invitation token is returned exactly once and NEVER persisted or logged.
     * Only the professor's future authenticated admin UI may invoke this.
     *
     * @return array{id:int, token:string}
     */
    public function issueInvitation(
        int $studyId,
        string $email,
        ?DateTimeImmutable $expiresAt = null,
        ?string $name = null
    ): array {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            throw new SchedulingException('INVALID_EMAIL');
        }
        if ($name !== null && mb_strlen($name) > 200) {
            throw new SchedulingException('INVALID_NAME');
        }
        if ($expiresAt !== null && $expiresAt <= self::now()) {
            throw new SchedulingException('INVALID_EXPIRATION');
        }
        if ($this->db->table('studies')->where('id', $studyId)->countAllResults() !== 1) {
            throw new SchedulingException('STUDY_NOT_FOUND');
        }

        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->table('invitations')->insert([
            'study_id' => $studyId,
            'participant_email' => $email,
            'participant_name' => $name,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt === null ? null : self::format($expiresAt),
        ]);
        return ['id' => (int) $this->db->insertID(), 'token' => $token];
    }

    public function revokeInvitation(int $invitationId, ?DateTimeImmutable $now = null): void
    {
        $timestamp = self::format(self::utc($now ?? self::now()));
        $updated = $this->db->table('invitations')->where('id', $invitationId)
            ->where('revoked_at', null)->update(['revoked_at' => $timestamp]);
        if (!$updated || $this->db->affectedRows() !== 1) {
            throw new SchedulingException('INVITATION_NOT_FOUND_OR_REVOKED');
        }
        // Existing bookings remain active; the professor must manage them.
    }

    /**
     * Returns only sessions, capacity and time information: no participant PII.
     * This is internal until an authenticated-by-token participant API exists.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableSessions(string $token, ?DateTimeImmutable $now = null): array
    {
        $now = self::utc($now ?? self::now());
        $invitation = $this->getInvitation($token, false);
        $this->assertUsableInvitation($invitation);
        $active = $this->activeBooking((int) $invitation['id'], false);

        if ($this->isExpired($invitation, $now) && $active === null) {
            throw new SchedulingException('INVITATION_EXPIRED');
        }
        $study = $this->db->query('SELECT status FROM studies WHERE id = ?', [
            (int) $invitation['study_id'],
        ])->getRowArray();
        if ($study === null || $study['status'] !== 'active') {
            return [];
        }

        return $this->db->query(
            "SELECT s.id, s.starts_at, s.ends_at, s.location, s.capacity,
                    (s.capacity - (SELECT COUNT(*) FROM bookings b
                        WHERE b.session_id = s.id AND b.status = 'booked')) AS available_seats
             FROM sessions s
             WHERE s.study_id = ? AND s.status = 'open' AND s.starts_at > ?
             ORDER BY s.starts_at, s.id",
            [(int) $invitation['study_id'], self::format($now)]
        )->getResultArray();
    }

    /**
     * @return array{id:int, session_id:int, invitation_id:int}
     */
    public function book(string $token, int $sessionId, ?DateTimeImmutable $now = null): array
    {
        $now = self::utc($now ?? self::now());

        return $this->transaction(function () use ($token, $sessionId, $now): array {
            $invitation = $this->getInvitation($token, true);
            $this->assertUsableInvitation($invitation);
            if ($this->isExpired($invitation, $now)) {
                throw new SchedulingException('INVITATION_EXPIRED');
            }
            if ($this->activeBooking((int) $invitation['id'], true) !== null) {
                throw new SchedulingException('ALREADY_BOOKED');
            }
            $session = $this->lockSession($sessionId);
            $this->assertBookableSession($invitation, $session, $now);

            if ($this->reservedSeats($sessionId) >= (int) $session['capacity']) {
                throw new SchedulingException('SESSION_FULL');
            }
            return $this->insertBooking($invitation, $sessionId, null);
        });
    }

    /**
     * Releases one seat without deleting the booking history.
     */
    public function cancel(string $token, ?DateTimeImmutable $now = null): int
    {
        $now = self::utc($now ?? self::now());

        return $this->transaction(function () use ($token, $now): int {
            $invitation = $this->getInvitation($token, true);
            $this->assertUsableInvitation($invitation);
            $booking = $this->activeBooking((int) $invitation['id'], true);
            if ($booking === null) {
                throw new SchedulingException('NO_ACTIVE_BOOKING');
            }
            $session = $this->lockSession((int) $booking['session_id']);
            $this->assertChangeAllowed($session, $now);

            $this->db->table('bookings')->where('id', (int) $booking['id'])->update([
                'status' => 'canceled',
                'active_invitation_id' => null,
            ]);
            $this->recordEvent((int) $booking['id'], 'canceled', (int) $session['id'], null);
            return (int) $booking['id'];
        });
    }

    /**
     * Atomic transfer: the old reservation survives any validation/capacity failure.
     * One historical booking row is marked rescheduled; a new one becomes active.
     *
     * @return array{id:int, session_id:int, invitation_id:int}
     */
    public function reschedule(string $token, int $targetSessionId, ?DateTimeImmutable $now = null): array
    {
        $now = self::utc($now ?? self::now());

        return $this->transaction(function () use ($token, $targetSessionId, $now): array {
            $invitation = $this->getInvitation($token, true);
            $this->assertUsableInvitation($invitation);
            $oldBooking = $this->activeBooking((int) $invitation['id'], true);
            if ($oldBooking === null) {
                throw new SchedulingException('NO_ACTIVE_BOOKING');
            }
            $oldSessionId = (int) $oldBooking['session_id'];
            if ($oldSessionId === $targetSessionId) {
                throw new SchedulingException('SAME_SESSION');
            }

            // Consistent ascending session lock order prevents opposite moves
            // from deadlocking each other during simultaneous reschedules.
            $ids = [$oldSessionId, $targetSessionId];
            sort($ids, SORT_NUMERIC);
            $sessions = $this->db->query(
                'SELECT * FROM sessions WHERE id IN (?, ?) ORDER BY id FOR UPDATE',
                $ids
            )->getResultArray();
            $sessionById = [];
            foreach ($sessions as $session) {
                $sessionById[(int) $session['id']] = $session;
            }
            if (!isset($sessionById[$oldSessionId], $sessionById[$targetSessionId])) {
                throw new SchedulingException('SESSION_NOT_FOUND');
            }

            $this->assertChangeAllowed($sessionById[$oldSessionId], $now);
            $this->assertBookableSession($invitation, $sessionById[$targetSessionId], $now);
            $this->assertChangeAllowed($sessionById[$targetSessionId], $now);

            if ($this->reservedSeats($targetSessionId) >= (int) $sessionById[$targetSessionId]['capacity']) {
                throw new SchedulingException('SESSION_FULL');
            }

            $this->db->table('bookings')->where('id', (int) $oldBooking['id'])->update([
                'status' => 'rescheduled',
                'active_invitation_id' => null,
            ]);
            return $this->insertBooking($invitation, $targetSessionId, $oldSessionId);
        });
    }

    private function assertBookableSession(array $invitation, array $session, DateTimeImmutable $now): void
    {
        if ((int) $invitation['study_id'] !== (int) $session['study_id']) {
            throw new SchedulingException('WRONG_STUDY');
        }
        $study = $this->db->query('SELECT status FROM studies WHERE id = ?', [
            (int) $invitation['study_id'],
        ])->getRowArray();
        if ($study === null || $study['status'] !== 'active' || $session['status'] !== 'open') {
            throw new SchedulingException('SESSION_UNAVAILABLE');
        }
        if (self::parse($session['starts_at']) <= $now) {
            throw new SchedulingException('SESSION_STARTED');
        }
    }

    private function assertChangeAllowed(array $session, DateTimeImmutable $now): void
    {
        $deadline = self::parse($session['starts_at'])
            ->modify('-' . $this->changeCutoffHours . ' hours');
        if ($now >= $deadline) {
            throw new SchedulingException('CUTOFF_PASSED');
        }
    }

    private function assertUsableInvitation(array $invitation): void
    {
        if ($invitation['revoked_at'] !== null) {
            throw new SchedulingException('INVITATION_REVOKED');
        }
    }

    private function isExpired(array $invitation, DateTimeImmutable $now): bool
    {
        return $invitation['expires_at'] !== null
            && self::parse($invitation['expires_at']) <= $now;
    }

    private function getInvitation(string $token, bool $lock): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/D', $token)) {
            throw new SchedulingException('INVALID_INVITATION');
        }
        $row = $this->db->query(
            'SELECT * FROM invitations WHERE token_hash = ?' . ($lock ? ' FOR UPDATE' : ''),
            [hash('sha256', $token)]
        )->getRowArray();
        if ($row === null) {
            throw new SchedulingException('INVALID_INVITATION');
        }
        return $row;
    }

    private function activeBooking(int $invitationId, bool $lock): ?array
    {
        return $this->db->query(
            "SELECT * FROM bookings WHERE active_invitation_id = ? AND status = 'booked'"
            . ($lock ? ' FOR UPDATE' : ''),
            [$invitationId]
        )->getRowArray();
    }

    private function lockSession(int $sessionId): array
    {
        $row = $this->db->query('SELECT * FROM sessions WHERE id = ? FOR UPDATE', [
            $sessionId,
        ])->getRowArray();
        if ($row === null) {
            throw new SchedulingException('SESSION_NOT_FOUND');
        }
        return $row;
    }

    private function reservedSeats(int $sessionId): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS total FROM bookings WHERE session_id = ? AND status = 'booked'",
            [$sessionId]
        )->getRowArray();
        return (int) $result['total'];
    }

    /**
     * @return array{id:int, session_id:int, invitation_id:int}
     */
    private function insertBooking(array $invitation, int $sessionId, ?int $fromSessionId): array
    {
        $invitationId = (int) $invitation['id'];
        $this->db->table('bookings')->insert([
            'invitation_id' => $invitationId,
            'study_id' => (int) $invitation['study_id'],
            'session_id' => $sessionId,
            'active_invitation_id' => $invitationId,
            'status' => 'booked',
        ]);
        $bookingId = (int) $this->db->insertID();
        $this->recordEvent(
            $bookingId,
            $fromSessionId === null ? 'booked' : 'rescheduled',
            $fromSessionId,
            $sessionId
        );
        return ['id' => $bookingId, 'session_id' => $sessionId, 'invitation_id' => $invitationId];
    }

    private function recordEvent(int $bookingId, string $type, ?int $fromId, ?int $toId): void
    {
        $this->db->table('booking_events')->insert([
            'booking_id' => $bookingId,
            'event_type' => $type,
            'from_session_id' => $fromId,
            'to_session_id' => $toId,
        ]);
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

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private static function utc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }

    private static function format(DateTimeImmutable $time): string
    {
        return self::utc($time)->format('Y-m-d H:i:s.u');
    }

    private static function parse(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }
}
