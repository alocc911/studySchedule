<?php

namespace Tests\Integration;

use App\Services\SchedulingService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Live routed participant API tests on the isolated MySQL _ci database.
 * They prove that bearer credentials are required at the HTTP boundary.
 */
final class ParticipantBookingTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private BaseConnection $integrationDb;
    private SchedulingService $scheduling;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('STUDY_SCHEDULE_INTEGRATION') !== '1') {
            $this->markTestSkipped('Requires isolated CI database.');
        }
        $this->integrationDb = Database::connect('default', false);
        if (!str_ends_with($this->integrationDb->getDatabase(), '_ci')) {
            $this->fail('Refusing to alter a non-CI database.');
        }
        $this->integrationDb->query("SET time_zone = '+00:00'");
        foreach (['booking_events', 'bookings', 'invitations', 'sessions', 'studies'] as $table) {
            $this->integrationDb->query("DELETE FROM {$table}");
        }
        $this->scheduling = new SchedulingService($this->integrationDb);
    }

    private function study(string $title = 'Winery visits'): int
    {
        $id = $this->scheduling->createStudy($title);
        $this->scheduling->setStudyStatus($id, 'active');
        return $id;
    }

    private function session(int $study, int $days = 8, int $capacity = 1): int
    {
        $time = new DateTimeImmutable("+{$days} days", new DateTimeZone('UTC'));
        return $this->scheduling->createSession($study, $time, $time->modify('+1 hour'), $capacity, 'Winery meeting room');
    }

    private function token(int $study, ?DateTimeImmutable $expiry = null): string
    {
        return $this->scheduling->issueInvitation($study, 'participant@example.com', $expiry, 'Invited Guest')['token'];
    }

    private function authorized(string $token): self
    {
        $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
        return $this;
    }

    private function decoded($response): array
    {
        return json_decode($response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testMissingOrMalformedBearerTokenIsRejected(): void
    {
        $this->get('/participant/invitation')->assertStatus(404);
        $this->withHeaders(['Authorization' => 'Bearer not-a-valid-token'])
            ->get('/participant/invitation')->assertStatus(404);
        $this->assertSame(0, (int) $this->integrationDb->table('bookings')->countAllResults());
    }

    public function testSnapshotShowsOnlyOwnStudyAndNoOtherParticipantDetails(): void
    {
        $first = $this->study('Winery customers');
        $second = $this->study('Winery staff');
        $firstSession = $this->session($first, 8, 3);
        $secondSession = $this->session($second, 9, 2);
        $token = $this->token($first);
        $otherToken = $this->scheduling->issueInvitation($first, 'someoneelse@example.com')['token'];
        $this->scheduling->book($otherToken, $firstSession);

        $response = $this->authorized($token)->get('/participant/invitation');
        $response->assertStatus(200);
        $body = $this->decoded($response)['data'];
        $this->assertSame('Winery customers', $body['study']['title']);
        $this->assertSame('Invited Guest', $body['invitation']['name']);
        $this->assertNull($body['booking']);
        $this->assertCount(1, $body['sessions']);
        $this->assertSame($firstSession, $body['sessions'][0]['id']);
        $this->assertSame(2, $body['sessions'][0]['available_seats']);
        $this->assertNotSame($secondSession, $body['sessions'][0]['id']);
        $this->assertStringNotContainsString('someoneelse@example.com', $response->getJSON());
        $this->assertStringNotContainsString($otherToken, $response->getJSON());
        $this->assertStringNotContainsString($token, $response->getJSON());
    }

    public function testBookRescheduleCancelAndRebookThroughHttp(): void
    {
        $study = $this->study();
        $old = $this->session($study, 8, 1);
        $new = $this->session($study, 9, 3);
        $token = $this->token($study);

        $created = $this->authorized($token)->withBodyFormat('json')
            ->post('/participant/book', ['session_id' => $old]);
        $created->assertStatus(201);
        $this->assertSame($old, $this->decoded($created)['data']['session_id']);

        $this->authorized($token)->withBodyFormat('json')
            ->post('/participant/book', ['session_id' => $old])->assertStatus(409);

        $current = $this->decoded($this->authorized($token)->get('/participant/invitation'))['data'];
        $this->assertSame($old, $current['booking']['session']['id']);
        $this->assertTrue($current['booking']['can_cancel']);

        $moved = $this->authorized($token)->withBodyFormat('json')
            ->post('/participant/reschedule', ['session_id' => $new]);
        $moved->assertStatus(200);
        $this->assertSame($new, $this->decoded($moved)['data']['session_id']);

        $canceled = $this->authorized($token)->withBodyFormat('json')
            ->post('/participant/cancel', []);
        $canceled->assertStatus(200);
        $this->assertTrue($this->decoded($canceled)['data']['canceled']);

        $this->assertNull($this->decoded(
            $this->authorized($token)->get('/participant/invitation')
        )['data']['booking']);

        $this->authorized($token)->withBodyFormat('json')
            ->post('/participant/book', ['session_id' => $old])->assertStatus(201);

        $this->assertSame(1, (int) $this->integrationDb->table('bookings')
            ->where('status', 'booked')->countAllResults());
        $this->assertSame(3, (int) $this->integrationDb->table('bookings')->countAllResults());
    }

    public function testCrossStudyAndCapacityGuardRemainAuthoritative(): void
    {
        $first = $this->study();
        $second = $this->study('Other study');
        $firstSession = $this->session($first, 8, 1);
        $secondSession = $this->session($second, 9, 1);
        $firstToken = $this->token($first);
        $secondToken = $this->token($first);
        $wrong = $this->authorized($firstToken)->withBodyFormat('json')
            ->post('/participant/book', ['session_id' => $secondSession]);
        $wrong->assertStatus(409);
        $this->assertSame('WRONG_STUDY', $this->decoded($wrong)['error']);

        $this->authorized($firstToken)->withBodyFormat('json')
            ->post('/participant/book', ['session_id' => $firstSession])->assertStatus(201);
        $full = $this->authorized($secondToken)->withBodyFormat('json')
            ->post('/participant/book', ['session_id' => $firstSession]);
        $full->assertStatus(409);
        $this->assertSame('SESSION_FULL', $this->decoded($full)['error']);
    }

    public function testExpiredAndRevokedLinksCannotBook(): void
    {
        $study = $this->study();
        $session = $this->session($study);
        $revoked = $this->scheduling->issueInvitation($study, 'revoked@example.com');
        $this->scheduling->revokeInvitation($revoked['id']);
        $result = $this->authorized($revoked['token'])->get('/participant/invitation');
        $result->assertStatus(410);
        $this->assertSame('INVITATION_REVOKED', $this->decoded($result)['error']);

        $expiredToken = $this->token($study);
        $this->integrationDb->table('invitations')
            ->where('token_hash', hash('sha256', $expiredToken))
            ->update(['expires_at' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        $expired = $this->authorized($expiredToken)->get('/participant/invitation');
        $expired->assertStatus(410);
        $this->assertSame('INVITATION_EXPIRED', $this->decoded($expired)['error']);

        $notAvailable = $this->authorized($revoked['token'])->withBodyFormat('json')
            ->post('/participant/book', ['session_id' => $session]);
        $notAvailable->assertStatus(410);
        $this->assertSame(0, (int) $this->integrationDb->table('bookings')->countAllResults());
    }

    public function testInvalidMutationFormatAndDestructiveGetAreRejected(): void
    {
        $study = $this->study();
        $session = $this->session($study);
        $token = $this->token($study);

        $invalid = $this->authorized($token)->withBodyFormat('json')
            ->post('/participant/book', ['session_id' => 'not an integer']);
        $invalid->assertStatus(400);
        $this->assertSame('INVALID_REQUEST', $this->decoded($invalid)['error']);
        $this->assertSame(0, (int) $this->integrationDb->table('bookings')->countAllResults());

        $this->assertSame(0, (int) $this->integrationDb->table('bookings')->countAllResults());
        // FeatureTestTrait throws for a route that does not exist, whereas
        // a real HTTP request receives the framework's 404 response.
        $this->expectException(\CodeIgniter\Exceptions\PageNotFoundException::class);
        $this->authorized($token)->get('/participant/cancel');
    }
}
