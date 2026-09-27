<?php

namespace App\Domain\Imports;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Turns what people type into spreadsheets into clean values. Returns null
 * for "could not understand", so the importer can say which cell is wrong.
 */
class Values
{
    /**
     * Dates as Philippine offices write them: 2026-03-15, 03/15/2026 (month
     * first), 15-Mar-2026, March 15, 2026, or an Excel date serial.
     */
    public static function date(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        // Excel stores dates as days since 1899-12-30.
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $value) && (float) $value > 20000 && (float) $value < 80000) {
            return CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $value));
        }

        // Slashes are month first, as in US-style Philippine paperwork.
        foreach (['Y-m-d', 'm/d/Y', 'm/d/y', 'm-d-Y', 'd-M-Y', 'd-M-y', 'M d, Y', 'F d, Y', 'd F Y', 'd M Y', 'Y/m/d'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
            } catch (Throwable) {
                continue;
            }
            // No warnings: rejects overflow such as 02/30/2026 becoming March 2.
            // (A four-digit-year format also reads "26" as the year 26.)
            if ($date !== null && \DateTime::getLastErrors() === false && $date->year >= 1900) {
                return $date;
            }
        }

        return null;
    }

    /** "₱1,234.50", "PHP 1234.5", "1234" => 123450 centavos. */
    public static function cents(string $value): ?int
    {
        $clean = preg_replace('/(PHP|Php|php|₱|P(?=\s*\d)|,|\s)/u', '', trim($value));
        $negative = false;
        if (preg_match('/^\((.*)\)$/', (string) $clean, $m)) { // accounting negatives
            $clean = $m[1];
            $negative = true;
        }

        if ($clean === '' || ! preg_match('/^-?\d+(\.\d{1,2})?$/', (string) $clean)) {
            return null;
        }

        $cents = (int) round(((float) $clean) * 100);

        return $negative ? -$cents : $cents;
    }

    /** "1:30 PM", "13:30", "8:30am" => "13:30" */
    public static function time(string $value): ?string
    {
        $value = strtoupper(str_replace(' ', '', trim($value)));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^(\d{1,2}):(\d{2})(AM|PM)?$/', $value, $m)) {
            $hour = (int) $m[1];
            if (($m[3] ?? '') === 'PM' && $hour < 12) {
                $hour += 12;
            }
            if (($m[3] ?? '') === 'AM' && $hour === 12) {
                $hour = 0;
            }

            return $hour < 24 && (int) $m[2] < 60 ? sprintf('%02d:%s', $hour, $m[2]) : null;
        }

        return null;
    }

    /**
     * For duplicate detection: lower case, no punctuation, corporate
     * suffixes and honorifics removed. "ACME Trading Corp." = "Acme Trading Corporation".
     */
    public static function nameKey(string $name): string
    {
        $key = mb_strtolower(trim($name));
        $key = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $key);
        $key = preg_replace('/\b(inc|incorporated|corp|corporation|co|company|ltd|limited|opc|llc|the|atty|mr|mrs|ms|dr|engr|sps|spouses)\b/u', ' ', (string) $key);

        return trim((string) preg_replace('/\s+/', ' ', (string) $key));
    }

    public static function tinKey(string $tin): string
    {
        return substr((string) preg_replace('/\D/', '', $tin), 0, 9);
    }

    /** Header "Client e-mail " => "client_email" for matching column aliases. */
    public static function headerKey(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($header))), '_');
    }
}
