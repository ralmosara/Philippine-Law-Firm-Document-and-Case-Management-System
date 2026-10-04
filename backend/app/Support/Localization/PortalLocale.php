<?php

namespace App\Support\Localization;

use Carbon\CarbonInterface;

/** The client portal's languages: English (the default) and Filipino. */
final class PortalLocale
{
    public const LOCALES = ['en' => 'English', 'fil' => 'Filipino'];

    public static function normalize(?string $locale): string
    {
        return array_key_exists((string) $locale, self::LOCALES) ? (string) $locale : 'en';
    }

    /** "October 4, 2026" or "Oktubre 4, 2026", in the current language. */
    public static function date(?CarbonInterface $date): string
    {
        return $date ? $date->copy()->locale(app()->getLocale())->translatedFormat('F j, Y') : '';
    }
}
