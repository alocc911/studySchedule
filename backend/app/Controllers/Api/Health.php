<?php

namespace App\Controllers\Api;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;
use Throwable;

/**
 * Non-sensitive service probes. Do not expose exception details to callers.
 */
final class Health extends Controller
{
    public function index(): ResponseInterface
    {
        return $this->response->setJSON([
            'status' => 'ok',
            'service' => 'study-schedule-api',
        ]);
    }

    public function ready(): ResponseInterface
    {
        try {
            $db = Database::connect();
            $db->query('SELECT 1');
        } catch (Throwable $exception) {
            log_message('error', 'Database readiness check failed: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return $this->response->setStatusCode(503)->setJSON([
                'status' => 'unavailable',
            ]);
        }

        return $this->response->setJSON([
            'status' => 'ok',
            'database' => 'connected',
        ]);
    }
}
