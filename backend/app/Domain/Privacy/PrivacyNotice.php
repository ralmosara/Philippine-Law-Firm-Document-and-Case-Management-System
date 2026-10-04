<?php

namespace App\Domain\Privacy;

use App\Domain\Matters\Models\Firm;

/**
 * The privacy notice shown on the intake form and to portal clients. A
 * firm can write its own; otherwise this plain-language notice under the
 * Data Privacy Act of 2012 is used, filled in with the firm's details.
 * Have the firm's DPO review whichever is used.
 *
 * In Filipino (for portal clients who chose it): the firm's own Filipino
 * notice; else, if the firm wrote its own English notice, that one (a stock
 * translation would not say what the firm's notice says); else the
 * Filipino version of the default.
 */
class PrivacyNotice
{
    public static function text(Firm $firm, string $locale = 'en'): string
    {
        if ($locale === 'fil') {
            if (filled($firm->privacy_notice_fil)) {
                return (string) $firm->privacy_notice_fil;
            }
            if (blank($firm->privacy_notice)) {
                return self::defaultFilipino($firm);
            }
        }

        return filled($firm->privacy_notice) ? (string) $firm->privacy_notice : self::default($firm);
    }

    public static function default(Firm $firm): string
    {
        $contact = $firm->dpo_email ?: ($firm->email ?: 'the firm');
        $dpo = $firm->dpo_name ? "{$firm->dpo_name}, our Data Protection Officer" : 'our Data Protection Officer';

        return <<<TEXT
            {$firm->name} collects and uses your personal information to evaluate your concern, check for conflicts of interest, represent you, communicate with you, bill for our services and meet our legal and professional obligations, in accordance with the Data Privacy Act of 2012 (Republic Act No. 10173).

            What we collect: your name and contact details, the facts and documents you give us about your matter, the names of other parties, and billing and payment records. Some of it may be sensitive personal information, such as information about legal proceedings.

            Who sees it: our lawyers and staff who work on your matter, and, where your matter requires it, courts, government agencies and opposing parties. Our service providers (hosting, e-mail, payments) process it for us under confidentiality obligations. We do not sell your information.

            How long we keep it: for as long as your matter is active, then for {$firm->retention_years} years after it closes, or longer where the law requires, after which we securely dispose of it.

            Your rights: you may ask to see, correct or delete your information, object to its processing, or get a copy you can take elsewhere, subject to our duties of confidentiality and record-keeping. Some requests may be limited while the law or a pending case requires us to keep records. You may also complain to the National Privacy Commission.

            To exercise your rights or ask a question, contact {$dpo} at {$contact}, or use "My data" in the client portal.
            TEXT;
    }

    public static function defaultFilipino(Firm $firm): string
    {
        $email = $firm->dpo_email ?: $firm->email;
        $dpo = $firm->dpo_name ? "kay {$firm->dpo_name}, ang aming Data Protection Officer," : 'sa aming Data Protection Officer';
        $contact = $email ? "{$dpo} sa {$email}" : $dpo;

        return <<<TEXT
            Kinokolekta at ginagamit ng {$firm->name} ang iyong personal na impormasyon upang suriin ang iyong usapin, tiyaking walang conflict of interest, katawanin ka, makipag-ugnayan sa iyo, singilin ang aming serbisyo, at tuparin ang aming mga legal at propesyonal na obligasyon, alinsunod sa Data Privacy Act of 2012 (Republic Act No. 10173).

            Ano ang aming kinokolekta: ang iyong pangalan at contact details, ang mga katotohanan at dokumentong ibinibigay mo tungkol sa iyong kaso, ang pangalan ng ibang partido, at mga rekord ng singil at bayad. Maaaring sensitibong personal na impormasyon ang ilan dito, gaya ng impormasyon tungkol sa mga legal na proseso.

            Sino ang nakakakita nito: ang aming mga abogado at kawaning humahawak sa iyong kaso, at, kung kailangan ng iyong kaso, ang mga hukuman, ahensya ng pamahalaan at kabilang partido. Pinoproseso ito para sa amin ng aming mga service provider (hosting, email, bayad) sa ilalim ng tungkuling panatilihin itong kumpidensyal. Hindi namin ibinebenta ang iyong impormasyon.

            Gaano katagal namin ito itinatago: habang aktibo ang iyong kaso, at sa loob ng {$firm->retention_years} taon matapos itong isara, o mas matagal kung hinihingi ng batas, saka namin ito ligtas na itatapon.

            Ang iyong mga karapatan: maaari mong hilingin na makita, itama o burahin ang iyong impormasyon, tutulan ang pagproseso nito, o kumuha ng kopyang madadala mo sa iba, alinsunod sa aming tungkulin sa pagiging kumpidensyal at sa pagtatago ng rekord. Maaaring limitado ang ilang kahilingan habang hinihingi ng batas o ng nakabinbing kaso na itago namin ang mga rekord. Maaari ka ring magreklamo sa National Privacy Commission.

            Upang gamitin ang iyong mga karapatan o magtanong, makipag-ugnayan {$contact} o gamitin ang "Aking datos" sa client portal.
            TEXT;
    }
}
