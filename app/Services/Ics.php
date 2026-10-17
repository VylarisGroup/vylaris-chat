<?php
declare(strict_types=1);

namespace App\Services;

/** Minimaler, standardkonformer iCalendar-Generator (RFC 5545): CRLF, Maskierung, Zeilenumbruch bei 75 Oktetten, UTC-Zeiten. */
final class Ics
{
    public static function escape(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $s);
    }

    /** Faltet eine Inhaltszeile auf höchstens 75 Oktette, ohne Mehrbyte-Zeichen zu trennen. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $cur = '';
        $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (strlen($cur) + strlen($ch) > $limit) {
                $out .= $cur . "\r\n ";
                $cur = '';
                $limit = 74; // Folgezeilen beginnen mit einem Leerzeichen
            }
            $cur .= $ch;
        }
        return $out . $cur;
    }

    private static function utc(string $mysqlUtc): string
    {
        return gmdate('Ymd\THis\Z', strtotime($mysqlUtc . ' UTC'));
    }

    private static function date(string $mysqlUtc): string
    {
        return gmdate('Ymd', strtotime($mysqlUtc . ' UTC'));
    }

    /**
     * @param array<string,mixed> $e Zeile aus calendar_events, ergänzt um creator_name und conversation_name
     * @return string[] Inhaltszeilen (ungefaltet)
     */
    public static function vevent(array $e): array
    {
        $allDay = (bool) $e['all_day'];
        $lines = ['BEGIN:VEVENT', 'UID:' . $e['uid'] . '@' . self::host(), 'DTSTAMP:' . self::utc((string) $e['updated_at']), 'SEQUENCE:' . (int) $e['sequence']];
        if ($allDay) {
            $start = (string) $e['starts_at'];
            $end = $e['ends_at'] !== null ? (string) $e['ends_at'] : gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + 86400);
            $lines[] = 'DTSTART;VALUE=DATE:' . self::date($start);
            $lines[] = 'DTEND;VALUE=DATE:' . self::date($end);
        } else {
            $start = (string) $e['starts_at'];
            $end = $e['ends_at'] !== null ? (string) $e['ends_at'] : gmdate('Y-m-d H:i:s', strtotime($start . ' UTC') + 3600);
            $lines[] = 'DTSTART:' . self::utc($start);
            $lines[] = 'DTEND:' . self::utc($end);
        }
        $lines[] = 'SUMMARY:' . self::escape((string) $e['title']);
        $desc = trim((string) $e['description']);
        $who = trim((string) ($e['creator_name'] ?? ''));
        $where = trim((string) ($e['conversation_name'] ?? ''));
        $footer = ($who !== '' ? 'Organisiert von ' . $who : '') . ($where !== '' ? ($who !== '' ? ' in ' : 'In ') . '«' . $where . '»' : '');
        $full = trim($desc . ($footer !== '' ? "\n\n" . $footer : ''));
        if ($full !== '') {
            $lines[] = 'DESCRIPTION:' . self::escape($full);
        }
        if (trim((string) $e['location']) !== '') {
            $lines[] = 'LOCATION:' . self::escape((string) $e['location']);
        }
        $lines[] = 'URL:' . base_url() . '/?c=' . (int) $e['conversation_id'] . '&m=' . (int) $e['message_id'];
        $lines[] = 'STATUS:' . ($e['cancelled_at'] !== null ? 'CANCELLED' : 'CONFIRMED');
        $lines[] = 'TRANSP:' . ($allDay ? 'TRANSPARENT' : 'OPAQUE');
        if (!$allDay && $e['cancelled_at'] === null) {
            array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Erinnerung', 'TRIGGER:-PT15M', 'END:VALARM');
        }
        $lines[] = 'END:VEVENT';
        return $lines;
    }

    /** @param array<int,array<string,mixed>> $events */
    public static function calendar(array $events, string $name, bool $publishMethod = false): string
    {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Vylaris//Messenger//DE', 'CALSCALE:GREGORIAN'];
        if ($publishMethod) {
            $lines[] = 'METHOD:PUBLISH';
        }
        $lines[] = 'X-WR-CALNAME:' . self::escape($name);
        $lines[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT1H';
        $lines[] = 'X-PUBLISHED-TTL:PT1H';
        foreach ($events as $e) {
            array_push($lines, ...self::vevent($e));
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private static function host(): string
    {
        return (string) (parse_url(base_url(), PHP_URL_HOST) ?: 'messenger.local');
    }
}
