<?php

namespace App\Controllers\Api;

use App\Services\SchedulingException;
use App\Services\SchedulingService;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * A personal invitation link is a bearer credential, never a participant
 * password or a proof of identity. No session cookies and no public admin access.
 *
 * Invitation tokens travel only in Authorization headers, never URLs or logs.
 */
final class Participant extends Controller
{
    private function json(array $body, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)
            ->setHeader('Cache-Control', 'no-store, private')
            ->setHeader('Pragma', 'no-cache')
            ->setHeader('Referrer-Policy', 'no-referrer')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setHeader('Vary', 'Authorization')
            ->setJSON($body);
    }

    private function token(): string
    {
        $header = $this->request->getHeaderLine('Authorization');
        if (!preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/D', $header, $matches)) {
            throw new SchedulingException('INVALID_INVITATION', 'That invitation link is invalid.');
        }
        return $matches[1];
    }

    private function sessionId(): int
    {
        if (strlen($this->request->getBody()) > 2048 ||
            !str_starts_with(strtolower($this->request->getHeaderLine('Content-Type')), 'application/json')) {
            throw new SchedulingException('INVALID_REQUEST', 'Invalid request format.');
        }
        $payload = $this->request->getJSON(true);
        if (!is_array($payload) || array_is_list($payload) ||
            !isset($payload['session_id']) ||
            !is_int($payload['session_id']) ||
            $payload['session_id'] < 1) {
            throw new SchedulingException('INVALID_REQUEST', 'Choose a valid session.');
        }
        return $payload['session_id'];
    }

    private function handle(callable $callback, int $status = 200): ResponseInterface
    {
        try {
            return $this->json(['data' => $callback()], $status);
        } catch (SchedulingException $exception) {
            $httpStatus = match ($exception->reason) {
                'INVALID_INVITATION', 'STUDY_NOT_FOUND' => 404,
                'INVITATION_EXPIRED', 'INVITATION_REVOKED' => 410,
                'INVALID_REQUEST' => 400,
                'SESSION_NOT_FOUND', 'WRONG_STUDY', 'SESSION_UNAVAILABLE',
                'SESSION_STARTED', 'SESSION_FULL', 'ALREADY_BOOKED',
                'NO_ACTIVE_BOOKING', 'CUTOFF_PASSED', 'SAME_SESSION' => 409,
                default => 422,
            };
            // No credentials, contact details, raw database errors or SQL in responses.
            return $this->json(['error' => $exception->reason, 'message' => $exception->getMessage()], $httpStatus);
        } catch (Throwable $exception) {
            log_message('error', 'Participant scheduling request failed: {class}', [
                'class' => get_class($exception),
            ]);
            return $this->json(['error' => 'SERVER_ERROR', 'message' => 'Unable to complete the request.'], 500);
        }
    }

    public function invitation(): ResponseInterface
    {
        return $this->handle(fn () => (new SchedulingService())->invitationSnapshot($this->token()));
    }

    public function book(): ResponseInterface
    {
        return $this->handle(function (): array {
            $token = $this->token();
            $session = $this->sessionId();
            return (new SchedulingService())->book($token, $session);
        }, 201);
    }

    public function cancel(): ResponseInterface
    {
        return $this->handle(function (): array {
            $token = $this->token();
            return ['booking_id' => (new SchedulingService())->cancel($token), 'canceled' => true];
        });
    }

    public function reschedule(): ResponseInterface
    {
        return $this->handle(function (): array {
            $token = $this->token();
            $session = $this->sessionId();
            return (new SchedulingService())->reschedule($token, $session);
        });
    }
}
