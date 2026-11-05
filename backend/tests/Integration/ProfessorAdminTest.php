<?php

namespace Tests\Integration;

use App\Services\AdminService;
use App\Services\SchedulingException;
use App\Services\SchedulingService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

final class ProfessorAdminTest extends CIUnitTestCase
{
    private BaseConnection $adminDb;
    private AdminService $admin;
    private SchedulingService $scheduler;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('STUDY_SCHEDULE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires isolated CI database.');
        }
        $this->adminDb = Database::connect('default', false);
        if (!str_ends_with($this->adminDb->getDatabase(), '_ci')) {
            $this->fail('Refusing to modify non-CI database.');
        }
        $this->adminDb->query("SET time_zone = '+00:00'");
        foreach (['notification_outbox','booking_events','bookings','invitations','sessions','studies'] as $table) {
            $this->adminDb->query("DELETE FROM {$table}");
        }
        $this->admin = new AdminService($this->adminDb);
        $this->scheduler = new SchedulingService($this->adminDb);
    }

    private function study(): int
    {
        $id = $this->scheduler->createStudy('Winery workforce interviews');
        $this->scheduler->setStudyStatus($id, 'active');
        return $id;
    }

    private function slot(int $days): array
    {
        $start = new DateTimeImmutable("+{$days} days", new DateTimeZone('UTC'));
        return [
            'start' => $start,
            'end' => $start->modify('+1 hour'),
            'capacity' => 2,
            'location' => 'Test winery',
        ];
    }

    private function assertReason(string $reason, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected {$reason}");
        } catch (SchedulingException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    public function testStudyEditingAndDashboard(): void
    {
        $id = $this->study();
        $this->admin->updateStudy($id, [
            'title' => 'Updated study',
            'timezone' => 'America/New_York',
            'description' => 'A description',
            'instructions' => 'Arrive early',
        ]);
        $study = $this->admin->studies()[0];
        $this->assertSame('Updated study', $study['title']);
        $this->assertSame('America/New_York', $study['timezone']);
        $this->assertSame(1, $this->admin->dashboard()['studies']);
    }

    public function testLocalStudyTimesAndDstTransitions(): void
    {
        $id = $this->study();
        $this->admin->updateStudy($id, [
            'title' => 'Timezone study',
            'timezone' => 'America/New_York',
            'description' => '',
            'instructions' => '',
        ]);
        $date = $this->admin->studyLocalTime($id, '2027-01-15T10:30');
        $this->assertSame('2027-01-15T15:30', $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\\TH:i'));
        $this->assertReason('INVALID_LOCAL_TIME', fn () => $this->admin->studyLocalTime($id, '2027-03-14T02:30'));
        $this->assertReason('AMBIGUOUS_LOCAL_TIME', fn () => $this->admin->studyLocalTime($id, '2027-11-07T01:30'));
    }

    public function testBulkCreateRollsBackWhenAnySessionOverlaps(): void
    {
        $id = $this->study();
        $existing = $this->slot(12);
        $this->admin->createSessions($id, [$existing]);
        $first = $this->slot(15);
        $this->assertReason('SCHEDULE_CONFLICT', fn () => $this->admin->createSessions($id, [$first, $existing]));
        $this->assertCount(1, $this->admin->sessions());
    }

    public function testSessionCapacityAndCancellationRequiresEmptySeats(): void
    {
        $id = $this->study();
        $sessionId = $this->admin->createSessions($id, [$this->slot(12)])[0];
        $inv = $this->scheduler->issueInvitation($id, 'one@example.com');
        $booking = $this->scheduler->book($inv['token'], $sessionId);

        $this->assertReason('OCCUPIED_SESSION', fn () => $this->admin->setSessionStatus($sessionId, 'canceled'));
        $this->admin->cancelBooking($booking['id']);
        $this->admin->setSessionStatus($sessionId, 'canceled');
        $this->assertSame('canceled', $this->admin->sessions()[0]['status']);
        $this->assertSame('canceled', $this->admin->bookings()[0]['status']);
    }

    public function testAdminReschedulePreservesHistoryAndStudyIsolation(): void
    {
        $first = $this->study();
        $second = $this->study();
        $old = $this->admin->createSessions($first, [$this->slot(12)])[0];
        $target = $this->admin->createSessions($first, [$this->slot(13)])[0];
        $wrong = $this->admin->createSessions($second, [$this->slot(14)])[0];
        $issued = $this->scheduler->issueInvitation($first, 'demo@example.com', null, 'Demo Participant');
        $booked = $this->scheduler->book($issued['token'], $old);
        $this->assertReason('WRONG_STUDY', fn () => $this->admin->rescheduleBooking($booked['id'], $wrong));
        $this->assertSame('booked', $this->admin->bookings()[0]['status']);
        $newId = $this->admin->rescheduleBooking($booked['id'], $target);
        $rows = $this->admin->bookings();
        $this->assertCount(2, $rows);
        $this->assertSame('booked', $rows[0]['status']);
        $this->assertSame($newId, (int) $rows[0]['id']);
        $this->assertSame('rescheduled', $rows[1]['status']);
        $this->assertSame('Demo Participant', $rows[0]['participant_name']);
    }

    public function testInvitationListingNeverReturnsBearerTokenHash(): void
    {
        $id = $this->study();
        $issued = $this->scheduler->issueInvitation($id, 'test@example.com');
        $invitations = $this->admin->invitations();
        $this->assertCount(1, $invitations);
        $this->assertArrayNotHasKey('token_hash', $invitations[0]);
        $this->assertStringNotContainsString($issued['token'], json_encode($invitations));
    }
}
