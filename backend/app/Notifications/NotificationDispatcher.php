<?php

namespace App\Notifications;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Small cPanel-friendly queue runner. No Redis, worker service or third-party
 * job infrastructure required. Cron calls it every few minutes.
 *
 * Delivery is at-least-once: if a process crashes after SMTP accepts a message
 * but before the DB marks it sent, a retry can duplicate the email.
 */
final class NotificationDispatcher
{
    private BaseConnection $db;
    private MailTransport $mailer;

    public function __construct(?BaseConnection $database = null, ?MailTransport $mailer = null)
    {
        $this->db = $database ?? Database::connect('default');
        $this->db->query("SET time_zone = '+00:00'");
        $this->mailer = $mailer ?? new SmtpMailTransport();
    }

    /**
     * @return array{sent:int,failed:int,processed:int}
     */
    public function run(int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Processing limit must be between 1 and 100.');
        }
        $stats = ['sent' => 0, 'failed' => 0, 'processed' => 0];

        // A crashed fifth attempt cannot remain permanently in 'sending'.
        $this->db->query(
            "UPDATE notification_outbox
             SET status = 'failed', last_error_code = 'ATTEMPTS_EXHAUSTED',
                 claimed_at = NULL, claim_key = NULL
             WHERE status = 'sending' AND attempts >= 5
               AND claimed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE)"
        );

        for ($index = 0; $index < $limit; $index++) {
            $row = $this->claim();
            if ($row === null) {
                break;
            }
            $stats['processed']++;
            try {
                $payload = json_decode((string) $row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    throw new \RuntimeException('Invalid notification payload.');
                }
                $rendered = NotificationTemplate::compose(
                    (string) ($payload['kind'] ?? ''),
                    $payload,
                    (string) $row['recipient_role']
                );
                $this->mailer->send(
                    (string) $row['recipient_email'],
                    $rendered['subject'],
                    $rendered['text'],
                    $rendered['html']
                );
                $this->markSent($row);
                $stats['sent']++;
            } catch (Throwable $exception) {
                $this->markFailure($row);
                // Do not log exception messages, which may contain SMTP
                // credentials, addresses, or rendered participant contents.
                log_message('warning', 'Notification delivery failed for outbox record {id}', [
                    'id' => (int) $row['id'],
                ]);
                $stats['failed']++;
            }
        }
        return $stats;
    }

    private function claim(): ?array
    {
        $this->db->transException(true);
        $this->db->transBegin();
        try {
            // Row-lock one due notification; other concurrent cron processes
            // wait, then observe the new claimed status and select another.
            $candidate = $this->db->query(
                "SELECT id FROM notification_outbox
                 WHERE attempts < 5
                   AND (
                       (status = 'pending' AND next_attempt_at <= UTC_TIMESTAMP(6))
                       OR
                       (status = 'sending' AND claimed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE))
                   )
                 ORDER BY id LIMIT 1 FOR UPDATE"
            )->getRowArray();

            if ($candidate === null) {
                $this->db->transCommit();
                return null;
            }

            $claimKey = bin2hex(random_bytes(16));
            $id = (int) $candidate['id'];
            $this->db->table('notification_outbox')->where('id', $id)->update([
                'status' => 'sending',
                'attempts' => $this->db->query(
                    'SELECT attempts + 1 AS n FROM notification_outbox WHERE id = ?',
                    [$id]
                )->getRow('n'),
                'claimed_at' => gmdate('Y-m-d H:i:s'),
                'claim_key' => $claimKey,
                'last_error_code' => null,
            ]);
            $row = $this->db->query(
                'SELECT * FROM notification_outbox WHERE id = ?', [$id]
            )->getRowArray();
            $this->db->transCommit();
            return $row;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    private function markSent(array $row): void
    {
        // Clear both recipient address and appointment payload after success;
        // the separate booking_events table remains the long-term audit trail.
        $this->db->table('notification_outbox')
            ->where('id', (int) $row['id'])
            ->where('claim_key', (string) $row['claim_key'])
            ->where('status', 'sending')
            ->update([
                'status' => 'sent',
                'recipient_email' => null,
                'payload_json' => null,
                'claimed_at' => null,
                'claim_key' => null,
                'last_error_code' => null,
                'sent_at' => gmdate('Y-m-d H:i:s'),
            ]);
    }

    private function markFailure(array $row): void
    {
        $attempts = (int) $row['attempts'];
        $delaySeconds = min(3600, 60 * (5 ** max(0, $attempts - 1)));
        $this->db->table('notification_outbox')
            ->where('id', (int) $row['id'])
            ->where('claim_key', (string) $row['claim_key'])
            ->where('status', 'sending')
            ->update([
                'status' => $attempts >= 5 ? 'failed' : 'pending',
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $delaySeconds),
                'claimed_at' => null,
                'claim_key' => null,
                'last_error_code' => 'DELIVERY_FAILURE',
            ]);
    }
}
