<?php

namespace Tests\Integration;

use App\Services\SchedulingException;
use App\Services\SchedulingService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Exercises the actual MySQL 8 schema, locks and foreign keys in CI.
 *
 * This test class intentionally never falls back to SQLite. It is gated by
 * STUDY_SCHEDULE_INTEGRATION=1 plus a dedicated _ci database name so it cannot
 * unexpectedly clear a developer or production database.
 */
final class SchedulingServiceTest extends CIUnitTestCase
{
    private BaseConnection $db;
    private SchedulingService $service;
    private DateTimeImmutable $base;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('STUDY_SCHEDULE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires dedicated CI MySQL database.');
        }

        $this->db = Database::connect('default', false);
        if (!str_ends_with((string) $this->db->getDatabase(), '_ci')) {
            $this->fail('Refusing to modify a database without the _ci suffix.');
        }
        $this->db->query("SET time_zone = '+00:00'");
        foreach (['booking_events', 'bookings', 'invitations', 'sessions', 'studies'] as $table) {
            $this->db->query("DELETE FROM {$table}");
        }
        $this->service = new SchedulingService($this->db);
        $this->base = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function study(): int
    {
        $id = $this->service->createStudy('Winery conversations', 'America/Chicago');
        $this->service->setStudyStatus($id, 'active');
        return $id;
    }

    private function session(int $study, int $days = 7, int $capacity = 1): int
    {
        $start = $this->base->modify("+{$days} days");
        return $this->service->createSession($study, $start, $start->modify('+60 minutes'), $capacity, 'Winery tasting room');
    }

    private function token(int $study, string $email = 'participant@example.com', ?DateTimeImmutable $expires = null): string
    {
        return $this->service->issueInvitation($study, $email, $expires)['token'];
    }

    private function assertRejection(string $reason, callable $call): void
    {
        try {
            $call();
            $this->fail('Expected scheduling rejection ' . $reason);
        } catch (SchedulingException $exception) {
            $this->assertSame($reason, $exception->reason);
        }
    }

    private function seatCount(int $sessionId): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS n FROM bookings WHERE session_id = ? AND status = 'booked'",
            [$sessionId]
        )->getRowArray()['n'];
    }

    public function testIndividualCapacityAndActiveInvitationUniqueness(): void
    {
        $study = $this->study();
        $session = $this->session($study);
        $first = $this->token($study, 'one@example.com');
        $second = $this->token($study, 'two@example.com');

        $booking = $this->service->book($first, $session);
        $this->assertGreaterThan(0, $booking['id']);
        $this->assertRejection('ALREADY_BOOKED', fn () => $this->service->book($first, $session));
        $this->assertRejection('SESSION_FULL', fn () => $this->service->book($second, $session));
        $this->assertSame(1, $this->seatCount($session));
    }

    public function testGroupCapacityAndSeatReleaseOnCancellation(): void
    {
        $study = $this->study();
        $session = $this->session($study, 7, 3);
        $tokens = [];
        for ($i = 0; $i < 4; $i++) {
            $tokens[] = $this->token($study, "group{$i}@example.com");
        }

        foreach (array_slice($tokens, 0, 3) as $token) {
            $this->service->book($token, $session);
        }
        $this->assertRejection('SESSION_FULL', fn () => $this->service->book($tokens[3], $session));
        $this->service->cancel($tokens[1]);
        $this->service->book($tokens[3], $session);

        $this->assertSame(3, $this->seatCount($session));
        $this->assertSame(
            1,
            (int) $this->db->table('booking_events')->where('event_type', 'canceled')->countAllResults()
        );
        $this->assertRejection('CAPACITY_BELOW_BOOKINGS', fn () => $this->service->changeSessionCapacity($session, 2));
    }

    public function testStudyIsolationAndAvailabilityDoesNotRevealAttendees(): void
    {
        $one = $this->study();
        $two = $this->study();
        $oneSession = $this->session($one, 7);
        $twoSession = $this->session($two, 8);
        $token = $this->token($one);

        $this->assertRejection('WRONG_STUDY', fn () => $this->service->book($token, $twoSession));
        $available = $this->service->availableSessions($token);
        $this->assertCount(1, $available);
        $this->assertSame((string) $oneSession, (string) $available[0]['id']);
        $this->assertArrayNotHasKey('participant_email', $available[0]);
        $this->assertArrayNotHasKey('participant_name', $available[0]);
    }

    public function testRescheduleIsAtomicAndPreservesHistory(): void
    {
        $study = $this->study();
        $old = $this->session($study, 7);
        $new = $this->session($study, 8);
        $targetFull = $this->session($study, 9);
        $token = $this->token($study, 'moving@example.com');
        $other = $this->token($study, 'other@example.com');
        $this->service->book($other, $targetFull);
        $original = $this->service->book($token, $old);

        $this->assertRejection('SESSION_FULL', fn () => $this->service->reschedule($token, $targetFull));
        $this->assertSame(1, $this->seatCount($old));
        $this->assertSame(0, $this->seatCount($new));

        $replacement = $this->service->reschedule($token, $new);
        $this->assertNotSame($original['id'], $replacement['id']);
        $this->assertSame(0, $this->seatCount($old));
        $this->assertSame(1, $this->seatCount($new));
        $this->assertSame(
            'rescheduled',
            $this->db->table('bookings')->select('status')->where('id', $original['id'])->get()->getRow('status')
        );
        $this->assertSame(2, (int) $this->db->table('booking_events')->where('booking_id', $replacement['id'])->countAllResults() + (int) $this->db->table('booking_events')->where('booking_id', $original['id'])->countAllResults());
    }

    public function testExpiredLinkStillManagesExistingBookingButCannotMakeANewOne(): void
    {
        $study = $this->study();
        $original = $this->session($study, 7);
        $replacement = $this->session($study, 8);
        $token = $this->token($study, 'expires@example.com', $this->base->modify('+1 hour'));

        $this->service->book($token, $original, $this->base);
        $this->service->reschedule($token, $replacement, $this->base->modify('+2 hours'));
        $this->service->cancel($token, $this->base->modify('+3 hours'));
        $this->assertRejection('INVITATION_EXPIRED', fn () => $this->service->book($token, $original, $this->base->modify('+3 hours')));
    }

    public function testRevocationDisablesSelfServiceWithoutDeletingReservation(): void
    {
        $study = $this->study();
        $session = $this->session($study);
        $issued = $this->service->issueInvitation($study, 'revoked@example.com');
        $this->assertSame(43, strlen($issued['token']));

        $this->service->book($issued['token'], $session);
        $row = $this->db->table('invitations')->where('id', $issued['id'])->get()->getRowArray();
        $this->assertSame(hash('sha256', $issued['token']), $row['token_hash']);
        $this->assertStringNotContainsString($issued['token'], json_encode($row));
        $this->service->revokeInvitation($issued['id']);

        $this->assertRejection('INVITATION_REVOKED', fn () => $this->service->cancel($issued['token']));
        $this->assertSame(1, $this->seatCount($session));
    }

    public function testCutoffBlocksLateChanges(): void
    {
        $study = $this->study();
        $session = $this->session($study, 7);
        $next = $this->session($study, 8);
        $token = $this->token($study);
        $this->service->book($token, $session);

        $lateTime = $this->base->modify('+6 days +1 hour');
        $this->assertRejection('CUTOFF_PASSED', fn () => $this->service->cancel($token, $lateTime));
        $this->assertRejection('CUTOFF_PASSED', fn () => $this->service->reschedule($token, $next, $lateTime));
        $this->assertSame(1, $this->seatCount($session));
    }

    public function testOverlappingSessionsAcrossStudiesAreRejected(): void
    {
        $one = $this->study();
        $two = $this->study();
        $this->session($one, 7);
        $start = $this->base->modify('+7 days +30 minutes');

        $this->assertRejection('SCHEDULE_CONFLICT', fn () => $this->service->createSession(
            $two,
            $start,
            $start->modify('+1 hour'),
            6
        ));
        $this->assertSame(1, (int) $this->db->table('sessions')->countAllResults());
    }

    public function testInactiveStudiesCannotAcceptBookingsButCanCancelExistingOnes(): void
    {
        $study = $this->study();
        $session = $this->session($study, 7);
        $token = $this->token($study);
        $newToken = $this->token($study, 'later@example.com');
        $this->service->book($token, $session);
        $this->service->setStudyStatus($study, 'closed');

        $this->assertRejection('SESSION_UNAVAILABLE', fn () => $this->service->book($newToken, $session));
        $this->service->cancel($token);
        $this->assertSame(0, $this->seatCount($session));
    }

    public function testInvalidInvitationRejected(): void
    {
        $this->assertRejection('INVALID_INVITATION', fn () => $this->service->book('not-a-real-token', 42));
    }

    /**
     * Reproduces the seat race against a real MySQL InnoDB database.
     * All forks establish separate database connections and start together.
     */
    public function testConcurrentLastSeatAndGroupCapacity(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl is required for concurrent MySQL integration tests.');
        }
        $study = $this->study();
        $individual = $this->session($study, 7, 1);
        $group = $this->session($study, 8, 6);
        $this->race($study, $individual, 2, 1);
        $this->race($study, $group, 7, 6);
    }

    private function race(int $study, int $sessionId, int $numberOfParticipants, int $expectedBooked): void
    {
        $tokens = [];
        for ($i = 0; $i < $numberOfParticipants; $i++) {
            $tokens[] = $this->token($study, "race{$sessionId}-{$i}@example.com");
        }

        $dir = sys_get_temp_dir() . '/study-schedule-race-' . bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        $children = [];
        for ($i = 0; $i < $numberOfParticipants; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork a worker.');
            }
            if ($pid === 0) {
                try {
                    file_put_contents("{$dir}/ready-{$i}", '1');
                    while (!file_exists("{$dir}/start")) {
                        usleep(10000);
                    }
                    $db = Database::connect('default', false);
                    $worker = new SchedulingService($db);
                    try {
                        $worker->book($tokens[$i], $sessionId);
                        file_put_contents("{$dir}/result-{$i}", 'booked');
                    } catch (SchedulingException $exception) {
                        file_put_contents("{$dir}/result-{$i}", $exception->reason);
                    }
                    exit(0);
                } catch (Throwable $exception) {
                    file_put_contents("{$dir}/result-{$i}", 'ERROR:' . $exception->getMessage());
                    exit(2);
                }
            }
            $children[] = $pid;
        }

        $deadline = microtime(true) + 10;
        while (count(glob($dir . '/ready-*')) < $numberOfParticipants && microtime(true) < $deadline) {
            usleep(10000);
        }
        touch($dir . '/start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0);
        }
        $outcomes = [];
        for ($i = 0; $i < $numberOfParticipants; $i++) {
            $outcomes[] = file_get_contents("{$dir}/result-{$i}");
            unlink("{$dir}/ready-{$i}");
            unlink("{$dir}/result-{$i}");
        }
        unlink($dir . '/start');
        rmdir($dir);

        $this->assertSame($expectedBooked, count(array_filter($outcomes, fn ($value) => $value === 'booked')));
        $this->assertSame($numberOfParticipants - $expectedBooked, count(array_filter($outcomes, fn ($value) => $value === 'SESSION_FULL')));
        $this->assertSame($expectedBooked, $this->seatCount($sessionId));
    }
}
