<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

/**
 * Explicitly reject both unauthenticated and non-professor accounts.
 * Does not rely on a JavaScript-only permission check.
 */
final class ProfessorGuard implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (!auth()->loggedIn()) {
            return Services::response()->setStatusCode(401)
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON(['error' => 'AUTH_REQUIRED']);
        }
        $user = auth()->user();
        if ($user === null || !$user->inGroup('superadmin')) {
            return Services::response()->setStatusCode(403)
                ->setHeader('Cache-Control', 'no-store')
                ->setJSON(['error' => 'FORBIDDEN']);
        }
        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }
}
