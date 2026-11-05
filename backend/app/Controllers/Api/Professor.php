<?php

namespace App\Controllers\Api;

use App\Services\AdminService;
use App\Services\SchedulingException;
use App\Services\SchedulingService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use DateTimeImmutable;
use Throwable;

/**
 * Same-origin professor API. Every route has the ProfessorGuard filter.
 * There is deliberately no CORS allowance for GitHub Pages or other origins.
 */
final class Professor extends Controller
{
    private function admin(): AdminService
    {
        return new AdminService();
    }

    private function scheduling(): SchedulingService
    {
        return new SchedulingService();
    }

    private function json(array $value, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('Referrer-Policy', 'no-referrer')
            ->setJSON($value);
    }

    private function perform(callable $callback, int $status = 200): ResponseInterface
    {
        try {
            return $this->json(['data' => $callback()], $status);
        } catch (SchedulingException $exception) {
            return $this->json(['error' => $exception->reason, 'message' => $exception->getMessage()], 422);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => 'INVALID_INPUT', 'message' => $exception->getMessage()], 422);
        } catch (Throwable $exception) {
            log_message('error', 'Professor API operation failed: {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->json(['error' => 'SERVER_ERROR'], 500);
        }
    }

    private function body(): array
    {
        if (strlen($this->request->getBody()) > 100000) {
            throw new \InvalidArgumentException('Request is too large.');
        }
        $json = $this->request->getJSON(true);
        if (!is_array($json) || array_is_list($json)) {
            throw new \InvalidArgumentException('Expected a JSON object.');
        }
        return $json;
    }

    private static function integer(mixed $value): int
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new \InvalidArgumentException('Expected an integer.');
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false || $validated < 1) {
            throw new \InvalidArgumentException('Expected a positive integer.');
        }
        return $validated;
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        if (!is_string($value) || strlen($value) > 40 ||
            !preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            throw new \InvalidArgumentException('Expected an ISO 8601 date with time zone.');
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $exception) {
            throw new \InvalidArgumentException('Invalid date or time.', 0, $exception);
        }
    }

    private function studyFilter(): ?int
    {
        $id = $this->request->getGet('study_id');
        return $id === null || $id === '' ? null : self::integer($id);
    }

    public function me(): ResponseInterface
    {
        return $this->json([
            'data' => ['email' => (string) (auth()->user()->email ?? '')],
            'csrf' => ['header' => csrf_header(), 'token' => csrf_hash()],
        ]);
    }

    public function logout(): ResponseInterface
    {
        auth()->logout();
        return $this->json(['data' => ['logged_out' => true]]);
    }

    public function dashboard(): ResponseInterface
    {
        return $this->perform(fn () => $this->admin()->dashboard());
    }

    public function studies(): ResponseInterface
    {
        return $this->perform(fn () => $this->admin()->studies());
    }

    public function createStudy(): ResponseInterface
    {
        return $this->perform(function (): array {
            $data = $this->body();
            $id = $this->scheduling()->createStudy(
                (string) ($data['title'] ?? ''),
                (string) ($data['timezone'] ?? 'America/Chicago'),
                (string) ($data['description'] ?? ''),
                (string) ($data['instructions'] ?? '')
            );
            return ['id' => $id];
        }, 201);
    }

    public function updateStudy(string $id): ResponseInterface
    {
        return $this->perform(function () use ($id): array {
            $this->admin()->updateStudy(self::integer($id), $this->body());
            return ['updated' => true];
        });
    }

    public function changeStudyStatus(string $id): ResponseInterface
    {
        return $this->perform(function () use ($id): array {
            $status = (string) ($this->body()['status'] ?? '');
            $this->scheduling()->setStudyStatus(self::integer($id), $status);
            return ['status' => $status];
        });
    }

    public function sessions(): ResponseInterface
    {
        return $this->perform(fn () => $this->admin()->sessions($this->studyFilter()));
    }

    public function createSessions(string $studyId): ResponseInterface
    {
        return $this->perform(function () use ($studyId): array {
            $rows = $this->body()['sessions'] ?? null;
            if (!is_array($rows) || count($rows) < 1 || count($rows) > 30) {
                throw new \InvalidArgumentException('Enter 1 to 30 session rows.');
            }
            $slots = [];
            $admin = $this->admin();
            $id = self::integer($studyId);
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new \InvalidArgumentException('Invalid session row.');
                }
                $slots[] = [
                    'start' => $admin->studyLocalTime($id, (string) ($row['start_local'] ?? '')),
                    'end' => $admin->studyLocalTime($id, (string) ($row['end_local'] ?? '')),
                    'capacity' => self::integer($row['capacity'] ?? null),
                    'location' => (string) ($row['location'] ?? ''),
                ];
            }
            $ids = $admin->createSessions($id, $slots);
            return ['session_ids' => $ids];
        }, 201);
    }

    public function changeCapacity(string $id): ResponseInterface
    {
        return $this->perform(function () use ($id): array {
            $capacity = self::integer($this->body()['capacity'] ?? null);
            $this->scheduling()->changeSessionCapacity(self::integer($id), $capacity);
            return ['capacity' => $capacity];
        });
    }

    public function changeSessionStatus(string $id): ResponseInterface
    {
        return $this->perform(function () use ($id): array {
            $status = (string) ($this->body()['status'] ?? '');
            $this->admin()->setSessionStatus(self::integer($id), $status);
            return ['status' => $status];
        });
    }

    public function invitations(): ResponseInterface
    {
        return $this->perform(fn () => $this->admin()->invitations($this->studyFilter()));
    }

    public function issueInvitation(): ResponseInterface
    {
        return $this->perform(function (): array {
            $data = $this->body();
            $expires = $data['expires_at'] ?? null;
            $issued = $this->scheduling()->issueInvitation(
                self::integer($data['study_id'] ?? null),
                (string) ($data['email'] ?? ''),
                $expires === null || $expires === '' ? null : self::date($expires),
                isset($data['name']) ? (string) $data['name'] : null
            );
            // Returned once. Never store/restore the URL; only the token hash is persisted.
            return ['id' => $issued['id'], 'token' => $issued['token']];
        }, 201);
    }

    public function revokeInvitation(string $id): ResponseInterface
    {
        return $this->perform(function () use ($id): array {
            $this->scheduling()->revokeInvitation(self::integer($id));
            return ['revoked' => true];
        });
    }

    public function bookings(): ResponseInterface
    {
        return $this->perform(fn () => $this->admin()->bookings($this->studyFilter()));
    }

    public function cancelBooking(string $id): ResponseInterface
    {
        return $this->perform(function () use ($id): array {
            $this->admin()->cancelBooking(self::integer($id));
            return ['canceled' => true];
        });
    }

    public function rescheduleBooking(string $id): ResponseInterface
    {
        return $this->perform(function () use ($id): array {
            $data = $this->body();
            $newId = $this->admin()->rescheduleBooking(
                self::integer($id),
                self::integer($data['session_id'] ?? null)
            );
            return ['booking_id' => $newId];
        });
    }

    public function exportBookings(): ResponseInterface
    {
        try {
            $rows = $this->admin()->bookings($this->studyFilter());
        } catch (Throwable $exception) {
            log_message('error', 'Booking export failed: {message}', [
                'message' => $exception->getMessage(),
            ]);
            return $this->json(['error' => 'SERVER_ERROR'], 500);
        }

        $fields = [
            'id', 'study_title', 'participant_name', 'participant_email',
            'status', 'starts_at', 'ends_at', 'timezone', 'location',
        ];
        $file = fopen('php://temp', 'w+');
        fputcsv($file, $fields);
        foreach ($rows as $row) {
            $values = [];
            foreach ($fields as $field) {
                $cell = (string) ($row[$field] ?? '');
                // Avoid spreadsheet formula execution when CSV is opened in Excel.
                if (preg_match('/^[\x00-\x20]*[=+@\-]/', $cell)) {
                    $cell = "'" . $cell;
                }
                $values[] = $cell;
            }
            fputcsv($file, $values);
        }
        rewind($file);
        $contents = stream_get_contents($file);
        fclose($file);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Cache-Control', 'no-store')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Content-Disposition', 'attachment; filename="research-bookings.csv"')
            ->setBody($contents);
    }
}
