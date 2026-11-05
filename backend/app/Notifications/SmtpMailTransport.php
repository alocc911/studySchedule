<?php

namespace App\Notifications;

use CodeIgniter\Email\Email;
use Config\Email as EmailConfig;
use RuntimeException;

/**
 * Uses plain SMTP. Set notifications.smtp.crypto=none only for local Mailpit
 * on loopback. Real servers require TLS/SSL and verified credentials.
 */
final class SmtpMailTransport implements MailTransport
{
    public function send(string $recipient, string $subject, string $text, string $html): void
    {
        $host = trim((string) env('notifications.smtp.host', ''));
        $from = trim((string) env('notifications.fromEmail', ''));
        $port = (int) env('notifications.smtp.port', 587);
        $crypto = strtolower(trim((string) env('notifications.smtp.crypto', 'tls')));
        $username = (string) env('notifications.smtp.username', '');
        $password = (string) env('notifications.smtp.password', '');

        if (!filter_var($from, FILTER_VALIDATE_EMAIL)
            || !filter_var($recipient, FILTER_VALIDATE_EMAIL)
            || $host === '' || $port < 1 || $port > 65535) {
            throw new RuntimeException('SMTP configuration is incomplete.');
        }

        if (!in_array($crypto, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('Invalid SMTP encryption configuration.');
        }
        if ($crypto === 'none' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException('Plain SMTP is restricted to local email testing.');
        }

        $config = new EmailConfig();
        $config->protocol = 'smtp';
        $config->SMTPHost = $host;
        $config->SMTPPort = $port;
        $config->SMTPCrypto = $crypto === 'none' ? '' : $crypto;
        $config->SMTPUser = $username;
        $config->SMTPPass = $password;
        $config->SMTPTimeout = 8;
        $config->mailType = 'html';
        $config->validate = true;
        $config->fromEmail = $from;
        $config->fromName = (string) env('notifications.fromName', 'Hospitality Research');

        $email = new Email($config);
        $email->setFrom($config->fromEmail, $config->fromName);
        $email->setTo($recipient);
        $email->setSubject($subject);
        $email->setMessage($html);
        $email->setAltMessage($text);
        if (!$email->send()) {
            // Never include the SMTP debug printout: it can contain sensitive
            // message contents and server details.
            throw new RuntimeException('SMTP delivery failed.');
        }
    }
}
