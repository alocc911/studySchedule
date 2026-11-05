<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Shared-hosting-compatible IP rate limit. Tokens are never embedded in keys.
 * High-entropy bearer tokens and database authorization remain primary security.
 */
final class ParticipantRateLimit implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $key = 'study-participant:' . hash('sha256', (string) $request->getIPAddress());
        if (!service('throttler')->check($key, 90, 60)) {
            return Services::response()->setStatusCode(429)
                ->setHeader('Cache-Control', 'no-store')
                ->setHeader('Retry-After', '60')
                ->setJSON(['error' => 'TOO_MANY_REQUESTS', 'message' => 'Please try again shortly.']);
        }
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
