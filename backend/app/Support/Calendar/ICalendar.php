<?php

namespace App\Support\Calendar;

use DateTimeInterface;

/**
 * Minimal RFC 5545 (iCalendar) writer: escaping, 75-octet line folding and
 * CRLF line endings, with Philippine time (no daylight saving) as the zone.
 */
class ICalendar
{
    public const TIMEZONE = 'Asia/Manila';

    /** @var list<string> */
    private array $lines = [];

    public function __construct(string $name, string $productId = '-//Lex PH//Practice Management//EN')
    {
        $this->add('BEGIN:VCALENDAR');
        $this->add('VERSION:2.0');
        $this->add("PRODID:{$productId}");
        $this->add('CALSCALE:GREGORIAN');
        $this->add('METHOD:PUBLISH');
        $this->add('X-WR-CALNAME:'.$this->escape($name));
        $this->add('X-WR-TIMEZONE:'.self::TIMEZONE);
        $this->add('REFRESH-INTERVAL;VALUE=DURATION:PT1H');
        $this->add('X-PUBLISHED-TTL:PT1H');
        foreach (['BEGIN:VTIMEZONE', 'TZID:'.self::TIMEZONE, 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0800', 'TZOFFSETTO:+0800', 'TZNAME:PHT', 'END:STANDARD', 'END:VTIMEZONE'] as $line) {
            $this->add($line);
        }
    }

    /**
     * @param  array{uid: string, summary: string, description?: ?string, location?: ?string, url?: ?string, start: DateTimeInterface, end?: ?DateTimeInterface, all_day: bool, stamp: DateTimeInterface, alarm_minutes?: ?int, status?: string}  $event
     */
    public function event(array $event): self
    {
        $this->add('BEGIN:VEVENT');
        $this->add('UID:'.$event['uid']);
        $this->add('DTSTAMP:'.$this->utc($event['stamp']));

        if ($event['all_day']) {
            $this->add('DTSTART;VALUE=DATE:'.$event['start']->format('Ymd'));
            $this->add('DTEND;VALUE=DATE:'.($event['end'] ?? (clone \DateTime::createFromInterface($event['start']))->modify('+1 day'))->format('Ymd'));
        } else {
            $this->add('DTSTART;TZID='.self::TIMEZONE.':'.$event['start']->format('Ymd\THis'));
            $this->add('DTEND;TZID='.self::TIMEZONE.':'.($event['end'] ?? (clone \DateTime::createFromInterface($event['start']))->modify('+1 hour'))->format('Ymd\THis'));
        }

        $this->add('SUMMARY:'.$this->escape($event['summary']));
        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION'] as $key => $property) {
            if (! empty($event[$key])) {
                $this->add("{$property}:".$this->escape($event[$key]));
            }
        }
        if (! empty($event['url'])) {
            $this->add('URL:'.$event['url']);
        }
        if (! empty($event['status'])) {
            $this->add('STATUS:'.$event['status']);
        }
        if (! empty($event['alarm_minutes'])) {
            $this->add('BEGIN:VALARM');
            $this->add('ACTION:DISPLAY');
            $this->add('DESCRIPTION:'.$this->escape($event['summary']));
            $this->add('TRIGGER:-PT'.$event['alarm_minutes'].'M');
            $this->add('END:VALARM');
        }
        $this->add('END:VEVENT');

        return $this;
    }

    public function render(): string
    {
        return implode("\r\n", [...$this->lines, 'END:VCALENDAR'])."\r\n";
    }

    private function add(string $line): void
    {
        // Fold at 75 octets without splitting a multi-byte character.
        $folded = [];
        while (strlen($line) > 75) {
            $cut = 75;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--;
            }
            $folded[] = substr($line, 0, $cut);
            $line = ' '.substr($line, $cut);
        }
        $folded[] = $line;

        array_push($this->lines, ...$folded);
    }

    private function escape(string $text): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $text);
    }

    private function utc(DateTimeInterface $at): string
    {
        return (new \DateTimeImmutable('@'.$at->getTimestamp()))->format('Ymd\THis\Z');
    }
}
