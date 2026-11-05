<?php

namespace App\Notifications;

interface MailTransport
{
    /**
     * Throws on any SMTP / transport failure. Never log credentials or content.
     */
    public function send(string $recipient, string $subject, string $text, string $html): void;
}
