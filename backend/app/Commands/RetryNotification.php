<?php

namespace App\Commands;

use App\Notifications\NotificationDispatcher;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class RetryNotification extends BaseCommand
{
    protected $group = 'Notifications';
    protected $name = 'notifications:retry';
    protected $description = 'Requeue one exhausted scheduling email after fixing SMTP settings.';
    protected $arguments = ['id' => 'ID of one failed notification in notification_outbox.'];

    public function run(array $params)
    {
        $id = $params[0] ?? '';
        if (!is_string($id) || !ctype_digit($id) || (int) $id < 1) {
            CLI::error('Provide one positive notification ID: php spark notifications:retry 123');
            return;
        }

        try {
            $queued = (new NotificationDispatcher())->retryFailed((int) $id);
            CLI::write($queued
                ? 'Notification requeued. The next scheduled sender will retry it.'
                : 'No failed notification exists with that ID.');
        } catch (Throwable $exception) {
            CLI::error('Unable to requeue the notification.');
        }
    }
}
