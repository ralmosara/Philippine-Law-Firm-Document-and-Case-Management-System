<?php

namespace App\Domain\Correspondence;

use Illuminate\Support\Str;
use ZBateson\MailMimeParser\IMessage;

/**
 * Whether an email really comes from the address in its From line. The
 * From line alone proves nothing; the receiving mail server checks SPF,
 * DKIM and DMARC and records the result. Only results from our own inbound
 * provider count: a sender can put any "Authentication-Results" header in
 * the message itself, so headers from other servers are ignored.
 */
class SenderVerification
{
    /**
     * From the message's Authentication-Results headers written by the
     * provider named in INBOUND_EMAIL_AUTHSERV_ID: DMARC pass, or a DKIM
     * pass for the From domain.
     */
    public function fromHeaders(IMessage $message, ?string $fromEmail): bool
    {
        $authserv = Str::lower(trim((string) config('services.inbound_email.authserv_id')));
        $domain = $this->domainOf($fromEmail);
        if ($authserv === '' || $domain === null) {
            return false;
        }

        for ($i = 0; ($header = $message->getHeader('Authentication-Results', $i)) !== null; $i++) {
            $value = Str::lower((string) $header->getRawValue());
            // "mx.provider.net; dmarc=pass ...": the authserv-id comes first.
            if (Str::squish(Str::before($value, ';')) !== $authserv) {
                continue;
            }
            if (preg_match('/\bdmarc=pass\b/', $value)) {
                return true;
            }
            if (preg_match_all('/\bdkim=pass\b[^;]*?header\.(?:d|i)=@?([a-z0-9.-]+)/', $value, $m)) {
                foreach ($m[1] as $signer) {
                    if ($this->aligned($signer, $domain)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * SendGrid Inbound Parse posts its DKIM results as a "dkim" field listing
     * each signing domain with "pass" or "fail". A pass for the From domain
     * verifies the sender.
     */
    public function fromSendGrid(?string $dkimField, ?string $fromEmail): bool
    {
        $domain = $this->domainOf($fromEmail);
        if ($dkimField === null || $domain === null) {
            return false;
        }
        preg_match_all('/@([a-z0-9.-]+)\s*:\s*pass/i', $dkimField, $m);
        foreach ($m[1] as $signer) {
            if ($this->aligned(Str::lower($signer), $domain)) {
                return true;
            }
        }

        return false;
    }

    /** The signing domain is the From domain or a parent of it (relaxed alignment). */
    private function aligned(string $signer, string $domain): bool
    {
        $signer = rtrim($signer, '.');

        return $signer === $domain || Str::endsWith($domain, ".{$signer}");
    }

    private function domainOf(?string $email): ?string
    {
        $domain = Str::lower(trim(Str::after((string) $email, '@')));

        return $email && str_contains($email, '@') && $domain !== '' ? $domain : null;
    }
}
