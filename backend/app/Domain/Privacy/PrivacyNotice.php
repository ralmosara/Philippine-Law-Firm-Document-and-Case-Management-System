<?php

namespace App\Domain\Privacy;

use App\Domain\Matters\Models\Firm;

/**
 * The privacy notice shown on the intake form and to portal clients. A
 * firm can write its own; otherwise this plain-language notice under the
 * Data Privacy Act of 2012 is used, filled in with the firm's details.
 * Have the firm's DPO review whichever is used.
 */
class PrivacyNotice
{
    public static function text(Firm $firm): string
    {
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
}
