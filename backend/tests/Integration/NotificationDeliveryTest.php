<?php

namespace Tests\Integration;

use App\Notifications\MailTransport;
use App\Notifications\NotificationDispatcher;
use App\Notifications\NotificationTemplate;
use App\Notifications\SmtpMailTransport;
use App\Services\AdminService;
use App\Services\SchedulingException;
use App\Services\SchedulingService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class FakeNotificationMailer implements MailTransport
{
    public array $messages = [];
    public bool $shouldFail = false;

    public function send(string $recipient, string $subject, string $text, string $html): void
    {
        if ($this->shouldFail) {
            throw new RuntimeException('Deliberate SMTP outage.');
        }
        $this->messages[] = compact('recipient', 'subject', 'text', 'html');
    }
}

final class NotificationDeliveryTest extends CIUnitTestCase
{
    private BaseConnection $integrationDb;
    private SchedulingService $scheduling;
    private AdminService $admin;
    private FakeNotificationMailer $mail;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('STUDY_SCHEDULE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Only runs with isolated MySQL integration database.');
        }
        $this->integrationDb = Database::connect('default', false);
        if (!str_ends_with((string) $this->integrationDb->getDatabase(), '_ci')) {
            $this->fail('Refusing to modify non-CI database.');
        }
        $this->integrationDb->query("SET time_zone = '+00:00'");
        foreach (['notification_outbox', 'booking_events', 'bookings', 'invitations', 'sessions', 'studies'] as $table) {
            $this->integrationDb->query("DELETE FROM {$table}");
        }
        $this->scheduling = new SchedulingService($this->integrationDb);
        $this->admin = new AdminService($this->integrationDb);
        $this->mail = new FakeNotificationMailer();
    }

    private function study(): int
    {
        $id = $this->scheduling->createStudy('Winery & Hospitality Conversations', 'America/Chicago');
        $this->scheduling->setStudyStatus($id, 'active');
        return $id;
    }

    private function session(int $studyId, int $days = 7, int $capacity = 2): int
    {
        $start = new DateTimeImmutable("+{$days} days", new DateTimeZone('UTC'));
        return $this->scheduling->createSession(
            $studyId, $start, $start->modify('+75 minutes'), $capacity,
            'Fictional Willow Creek Winery'
        );
    }

    private function invitation(int $studyId): array
    {
        return $this->scheduling->issueInvitation($studyId, 'participant@example.test', null, 'Morgan Example');
    }

    private function outbox(): array
    {
        return $this->integrationDb->query(
            'SELECT * FROM notification_outbox ORDER BY id'
        )->getResultArray();
    }

    public function testBookingQueuedInTransactionAndSentOnlyOnceAfterward(): void
    {
        $study = $this->study();
        $session = $this->session($study);
        $issued = $this->invitation($study);
        $this->scheduling->book($issued['token'], $session);

        $rows = $this->outbox();
        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame(0, (int) $rows[0]['attempts']);
        $this->assertStringNotContainsString($issued['token'], (string) $rows[0]['payload_json']);
        $this->assertSame([], $this->mail->messages);

        $sender = new NotificationDispatcher($this->integrationDb, $this->mail);
        $this->assertSame(['sent' => 1, 'failed' => 0, 'processed' => 1], $sender->run());
        $this->assertSame(['sent' => 0, 'failed' => 0, 'processed' => 0], $sender->run());
        $this->assertCount(1, $this->mail->messages);
        $text = $this->mail->messages[0]['text'];
        $this->assertStringContainsString('Appointment confirmed', $this->mail->messages[0]['subject']);
        $this->assertStringContainsString('Winery & Hospitality Conversations', $text);
        $this->assertStringContainsString('Fictional Willow Creek Winery', $text);
        $this->assertStringContainsString('America/Chicago', $text);
        $this->assertStringContainsString('Morgan Example', $text);
        $this->assertStringNotContainsString($issued['token'], $text);

        $row = $this->outbox()[0];
        $this->assertSame('sent', $row['status']);
        $this->assertNull($row['recipient_email']);
        $this->assertNull($row['payload_json']);
        $this->assertNotNull($row['sent_at']);
        $this->assertSame(1, (int) $this->integrationDb->table('bookings')
            ->where('status', 'booked')->countAllResults());
    }

    public function testTemporarySmtpFailureDoesNotUndoReservationAndRetries(): void
    {
        $study = $this->study();
        $session = $this->session($study);
        $token = $this->invitation($study)['token'];
        $this->scheduling->book($token, $session);

        $this->mail->shouldFail = true;
        $sender = new NotificationDispatcher($this->integrationDb, $this->mail);
        $this->assertSame(['sent' => 0, 'failed' => 1, 'processed' => 1], $sender->run(1));
        $row = $this->outbox()[0];
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertSame('DELIVERY_FAILURE', $row['last_error_code']);
        $this->assertSame(1, (int) $this->integrationDb->table('bookings')
            ->where('status', 'booked')->countAllResults());
        $this->assertSame(['sent' => 0, 'failed' => 0, 'processed' => 0], $sender->run());

        $this->integrationDb->table('notification_outbox')->where('id', $row['id'])
            ->update(['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 30)]);
        $this->mail->shouldFail = false;
        $this->assertSame(['sent' => 1, 'failed' => 0, 'processed' => 1], $sender->run());
        $this->assertSame(2, (int) $this->outbox()[0]['attempts']);
        $this->assertCount(1, $this->mail->messages);
    }

    public function testExhaustedFailuresStopAutomaticAttempts(): void
    {
        $study = $this->study();
        $session = $this->session($study);
        $token = $this->invitation($study)['token'];
        $this->scheduling->book($token, $session);

        $this->mail->shouldFail = true;
        $sender = new NotificationDispatcher($this->integrationDb, $this->mail);
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->assertSame(1, $sender->run(1)['failed']);
            $row = $this->outbox()[0];
            $this->assertSame($attempt, (int) $row['attempts']);
            if ($attempt < 5) {
                $this->integrationDb->table('notification_outbox')->where('id', $row['id'])
                    ->update(['next_attempt_at' => gmdate('Y-m-d H:i:s', time() - 30)]);
            }
        }
        $this->assertSame('failed', $this->outbox()[0]['status']);
        $this->assertSame(0, $sender->run()['processed']);
    }

    public function testRescheduleAndCancelEmailsKeepOriginalEventSnapshot(): void
    {
        $study = $this->study();
        $old = $this->session($study, 7);
        $new = $this->session($study, 9);
        $issued = $this->invitation($study);

        $this->scheduling->book($issued['token'], $old);
        $this->scheduling->reschedule($issued['token'], $new);
        $this->scheduling->cancel($issued['token']);

        $rows = $this->outbox();
        $this->assertCount(3, $rows);
        $this->assertSame('booked', json_decode($rows[0]['payload_json'], true)['kind']);
        $rescheduled = json_decode($rows[1]['payload_json'], true);
        $this->assertSame('rescheduled', $rescheduled['kind']);
        $this->assertNotSame($rescheduled['starts_at'], $rescheduled['previous_starts_at']);
        $canceled = json_decode($rows[2]['payload_json'], true);
        $this->assertSame('canceled', $canceled['kind']);
        $this->assertNull($canceled['starts_at']);

        $sender = new NotificationDispatcher($this->integrationDb, $this->mail);
        $this->assertSame(3, $sender->run()['sent']);
        $this->assertCount(3, $this->mail->messages);
        $this->assertStringContainsString('confirmed', $this->mail->messages[0]['subject']);
        $this->assertStringContainsString('rescheduled', $this->mail->messages[1]['subject']);
        $this->assertStringContainsString('Previous appointment:', $this->mail->messages[1]['text']);
        $this->assertStringContainsString('Current appointment:', $this->mail->messages[1]['text']);
        $this->assertStringContainsString('canceled', $this->mail->messages[2]['subject']);
        $this->assertStringContainsString('Canceled appointment:', $this->mail->messages[2]['text']);
    }

    public function testProfessorOverridesAlsoEnqueueNotifications(): void
    {
        $study = $this->study();
        $old = $this->session($study, 7);
        $new = $this->session($study, 9);
        $issued = $this->invitation($study);
        $initial = $this->scheduling->book($issued['token'], $old);
        $replacement = $this->admin->rescheduleBooking($initial['id'], $new);
        $this->admin->cancelBooking($replacement);

        $this->assertCount(3, $this->outbox());
        $this->assertSame(3, (new NotificationDispatcher($this->integrationDb, $this->mail))->run()['sent']);
    }

    public function testRejectedBookingNeverCreatesNotification(): void
    {
        $study = $this->study();
        $session = $this->session($study, 7, 1);
        $first = $this->invitation($study);
        $second = $this->scheduling->issueInvitation($study, 'second@example.test');
        $this->scheduling->book($first['token'], $session);
        try {
            $this->scheduling->book($second['token'], $session);
            $this->fail('Expected full-session rejection.');
        } catch (SchedulingException $exception) {
            $this->assertSame('SESSION_FULL', $exception->reason);
        }
        $this->assertCount(1, $this->outbox());
    }

    public function testEmailTemplatesEscapeMarkupAndDoNotContainInvitationCredentials(): void
    {
        $payload = [
            'study_title' => '<script>alert(1)</script>',
            'participant_name' => 'Test <Guest>',
            'participant_email' => 'guest@example.test',
            'timezone' => 'America/Chicago',
            'starts_at' => '2027-01-12 16:30:00.000000',
            'location' => 'Room A',
            'previous_starts_at' => null,
            'previous_location' => null,
        ];
        $content = NotificationTemplate::compose('booked', $payload, 'participant');
        $this->assertStringContainsString('&lt;script&gt;', $content['html']);
        $this->assertStringNotContainsString('<script>', $content['html']);
        $this->assertStringContainsString('CST', $content['text']);
        $this->assertStringContainsString('January 12, 2027', $content['text']);
        $this->assertStringContainsString('private invitation link', $content['text']);
    }

    public function testSmtpIsActuallyAcceptedByMailpit(): void
    {
        if (getenv('STUDY_SCHEDULE_MAILPIT_SMOKE') !== '1') {
            $this->markTestSkipped('Only runs when local Mailpit SMTP is available.');
        }
        $study = $this->study();
        $session = $this->session($study);
        $issued = $this->invitation($study);
        $this->scheduling->book($issued['token'], $session);
        $sender = new NotificationDispatcher($this->integrationDb, new SmtpMailTransport());
        $result = $sender->run(5);
        $this->assertSame(['sent' => 1, 'failed' => 0, 'processed' => 1], $result);
        $this->assertSame('sent', $this->outbox()[0]['status']);
    }
}
