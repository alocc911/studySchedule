<?php

namespace App\Notifications;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Formats immutable event snapshots. Does not know or reconstruct invitation
 * bearer tokens; recipients use the original private link for self-service.
 */
final class NotificationTemplate
{
    /**
     * @param array<string, mixed> $payload
     * @return array{subject:string,text:string,html:string}
     */
    public static function compose(string $kind, array $payload, string $recipientRole): array
    {
        if (!in_array($kind, ['booked', 'canceled', 'rescheduled'], true)) {
            throw new \InvalidArgumentException('Unknown notification event.');
        }
        if (!in_array($recipientRole, ['participant', 'professor'], true)) {
            throw new \InvalidArgumentException('Unknown recipient role.');
        }

        $title = (string) ($payload['study_title'] ?? '');
        $title = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $title) ?? '');
        $timezone = (string) ($payload['timezone'] ?? 'America/Chicago');
        $name = trim((string) ($payload['participant_name'] ?? ''));

        $heading = match ($kind) {
            'booked' => 'Appointment confirmed',
            'canceled' => 'Appointment canceled',
            'rescheduled' => 'Appointment rescheduled',
        };
        $subject = $heading . ' — ' . $title;
        $greeting = $recipientRole === 'participant'
            ? ($name === '' ? 'Hello,' : 'Hello ' . $name . ',')
            : 'Professor,';

        $description = match ($kind) {
            'booked' => 'A research appointment has been booked.',
            'canceled' => 'A research appointment has been canceled.',
            'rescheduled' => 'A research appointment has been moved to a new time.',
        };
        $lines = [$greeting, '', $description, '', 'Study: ' . $title];
        if ($recipientRole === 'professor') {
            $lines[] = 'Participant: ' . ($name === '' ? 'Invited participant' : $name);
            $lines[] = 'Email: ' . (string) ($payload['participant_email'] ?? '');
        }

        if ($kind === 'rescheduled') {
            $lines[] = '';
            $lines[] = 'Previous appointment:';
            $lines[] = self::date((string) ($payload['previous_starts_at'] ?? ''), $timezone);
            $lines[] = 'Previous location: ' . self::clean((string) ($payload['previous_location'] ?? ''));
        }

        if ($kind !== 'canceled') {
            $lines[] = '';
            $lines[] = 'Current appointment:';
            $lines[] = self::date((string) ($payload['starts_at'] ?? ''), $timezone);
            $lines[] = 'Location: ' . self::clean((string) ($payload['location'] ?? ''));
        } else {
            $lines[] = '';
            $lines[] = 'Canceled appointment:';
            $lines[] = self::date((string) ($payload['previous_starts_at'] ?? ''), $timezone);
            $lines[] = 'Location: ' . self::clean((string) ($payload['previous_location'] ?? ''));
        }

        $lines[] = '';
        $lines[] = 'Times are shown in the study time zone (' . $timezone . ').';
        $lines[] = $recipientRole === 'participant'
            ? 'To manage your appointment, return to the personal invitation link originally shared by the researcher. Please keep that link private.'
            : 'You can review the booking in your private professor workspace.';
        $lines[] = '';
        $lines[] = 'Hospitality Research — scheduling notifications only.';
        $text = implode("\n", $lines);
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="en"><head><meta charset="UTF-8"></head>'
            . '<body style="font-family:Arial,sans-serif;color:#29372c;line-height:1.65">'
            . '<main style="max-width:640px;margin:35px auto;padding:30px;border:1px solid #dce1d5">'
            . '<h1 style="font-size:24px;font-weight:500">' . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>'
            . '<p style="white-space:pre-line">' . $escaped . '</p>'
            . '</main></body></html>';

        return ['subject' => $subject, 'text' => $text, 'html' => $html];
    }

    private static function date(string $value, string $timezone): string
    {
        if ($value === '') {
            return 'Time unavailable';
        }
        try {
            $utc = new DateTimeImmutable($value, new DateTimeZone('UTC'));
            return $utc->setTimezone(new DateTimeZone($timezone))
                ->format('l, F j, Y \\a\\t g:i A T');
        } catch (\Throwable) {
            return 'Time unavailable';
        }
    }

    private static function clean(string $input): string
    {
        $value = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $input) ?? '');
        return $value === '' ? 'To be confirmed' : $value;
    }
}
