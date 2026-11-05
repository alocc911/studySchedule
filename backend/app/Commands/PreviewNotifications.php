<?php

namespace App\Commands;

use App\Notifications\NotificationTemplate;
use App\Notifications\SmtpMailTransport;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Sends three FICTIONAL examples to localhost Mailpit. Safe on a laptop and
 * CI, unavailable in production or for any remotely hosted SMTP server.
 */
final class PreviewNotifications extends BaseCommand
{
    protected $group = 'Notifications';
    protected $name = 'notifications:preview';
    protected $description = 'Send three sample scheduling messages to local Mailpit (development only).';

    public function run(array $params)
    {
        $host = strtolower(trim((string) env('notifications.smtp.host', '')));
        if (ENVIRONMENT === 'production'
            || !in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || strtolower(trim((string) env('notifications.smtp.crypto', ''))) !== 'none') {
            CLI::error('Previews require non-production mode and local unencrypted Mailpit SMTP.');
            return;
        }

        $start = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+8 days')->setTime(16, 0);
        $next = $start->modify('+2 days');
        $payload = [
            'study_title' => 'Sample Winery Guest Experiences',
            'timezone' => 'America/Chicago',
            'participant_name' => 'Alex Example',
            'participant_email' => 'alex@example.test',
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'ends_at' => $start->modify('+1 hour')->format('Y-m-d H:i:s'),
            'location' => 'Fictional Willow Creek Winery',
            'previous_starts_at' => $start->format('Y-m-d H:i:s'),
            'previous_ends_at' => $start->modify('+1 hour')->format('Y-m-d H:i:s'),
            'previous_location' => 'Fictional Willow Creek Winery',
        ];

        try {
            $mailer = new SmtpMailTransport();
            foreach (['booked', 'rescheduled', 'canceled'] as $kind) {
                $example = $payload;
                if ($kind === 'rescheduled') {
                    $example['starts_at'] = $next->format('Y-m-d H:i:s');
                    $example['ends_at'] = $next->modify('+1 hour')->format('Y-m-d H:i:s');
                }
                if ($kind === 'canceled') {
                    $example['starts_at'] = null;
                    $example['ends_at'] = null;
                    $example['location'] = null;
                }
                $rendered = NotificationTemplate::compose($kind, $example, 'participant');
                $mailer->send('alex@example.test', $rendered['subject'], $rendered['text'], $rendered['html']);
            }
            CLI::write('Three fictional scheduling emails sent to Mailpit. View http://127.0.0.1:8025', 'green');
        } catch (Throwable $exception) {
            CLI::error('Could not deliver preview messages to Mailpit. Check docker compose and SMTP settings.');
        }
    }
}
