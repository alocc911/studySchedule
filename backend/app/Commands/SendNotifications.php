<?php

namespace App\Commands;

use App\Notifications\NotificationDispatcher;
use CLI\Exception;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class SendNotifications extends BaseCommand
{
    protected $group = 'Notifications';
    protected $name = 'notifications:send';
    protected $description = 'Deliver queued appointment emails through configured SMTP.';
    protected $arguments = [
        'limit' => 'Optional number of notifications to process, 1 to 100 (default 20).',
    ];

    public function run(array $params)
    {
        $enabled = filter_var((string) env('notifications.enabled', 'false'), FILTER_VALIDATE_BOOLEAN);
        if (!$enabled) {
            CLI::write('Email delivery is disabled. Set notifications.enabled=true after configuring SMTP.', 'yellow');
            return;
        }
        $limit = $params[0] ?? '20';
        if (!ctype_digit((string) $limit) || (int) $limit < 1 || (int) $limit > 100) {
            CLI::error('Limit must be a whole number between 1 and 100.');
            return;
        }

        try {
            $stats = (new NotificationDispatcher())->run((int) $limit);
            CLI::write(sprintf(
                'Notifications processed: %d; sent: %d; failed: %d.',
                $stats['processed'], $stats['sent'], $stats['failed']
            ));
            if ($stats['failed'] > 0) {
                CLI::write('Failed messages remain queued for retry or manual review.', 'yellow');
            }
        } catch (Throwable $exception) {
            CLI::error('Notification processing stopped due to a server error.');
            // No message payload, SMTP password or recipient address in output.
            log_message('error', 'Notification command stopped: {class}', [
                'class' => get_class($exception),
            ]);
        }
    }
}
