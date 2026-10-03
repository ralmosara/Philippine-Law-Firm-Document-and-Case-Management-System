<?php

namespace App\Domain\Correspondence;

use App\Domain\Correspondence\Jobs\FileMatterEmail;
use App\Domain\Correspondence\Models\MatterEmail;
use App\Domain\Correspondence\Notifications\EmailNeedsReview;
use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;

/**
 * Email to matter. Each matter has a private address (plus-addressing on
 * one inbox: files+m-2026-0019.k2j4h5g6f7@inbound.firm.ph). Mail sent,
 * copied or forwarded to it is filed with the matter: the message as an
 * .eml file and each attachment as a file, virus-scanned and indexed like
 * any upload.
 *
 * Mail from the firm's staff or the matter's client is filed at once, when
 * the receiving server confirms the sender (SPF/DKIM/DMARC; see
 * SenderVerification). Mail from anyone else, or that cannot be confirmed,
 * waits for a lawyer to accept or reject it, so a leaked address or a forged
 * From line cannot fill a case file with junk.
 */
class InboundEmails
{
    private const TOKEN_LENGTH = 10;

    /** Stored body text is capped; the full message is in the .eml file. */
    private const BODY_LIMIT = 200_000;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly StoreMatterFile $store,
        private readonly SenderVerification $verification,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('services.inbound_email.address');
    }

    /** The matter's address, created on first use. */
    public function addressFor(Matter $matter): ?string
    {
        if (! self::enabled()) {
            return null;
        }
        if (! $matter->inbound_email_token) {
            $matter->forceFill(['inbound_email_token' => Str::lower(Str::random(self::TOKEN_LENGTH))])->saveQuietly();
        }
        [$local, $domain] = explode('@', (string) config('services.inbound_email.address'), 2);
        $code = Str::slug((string) $matter->reference) ?: 'matter';

        return "{$local}+{$code}.{$matter->inbound_email_token}@{$domain}";
    }

    /** A new address, when the old one has leaked; mail to the old one is no longer filed. */
    public function rotate(Matter $matter): ?string
    {
        $matter->forceFill(['inbound_email_token' => null])->saveQuietly();

        return $this->addressFor($matter);
    }

    /**
     * A message from the inbound webhook. Returns the matter emails created:
     * one per matter it was addressed to, none when no address matched.
     *
     * @param  list<string>  $envelopeRecipients
     * @param  string|null  $sendGridDkim  SendGrid's "dkim" field, when it posted one
     * @return list<MatterEmail>
     */
    public function receive(string $raw, array $envelopeRecipients = [], ?string $sendGridDkim = null): array
    {
        $message = $this->parse($raw);
        $from = $this->fromEmail($message);
        $verified = $this->verification->fromSendGrid($sendGridDkim, $from) || $this->verification->fromHeaders($message, $from);
        $recipients = [...$envelopeRecipients, ...$this->addresses($message, ['To', 'Cc', 'Delivered-To', 'X-Original-To', 'X-Forwarded-To', 'Envelope-To'])];

        $created = [];
        foreach ($this->mattersAddressed($recipients) as $matter) {
            $email = $this->tenant->runAs($matter->firm_id, fn () => $this->record($matter, $message, $raw, 'forward', null, $verified));
            if ($email) {
                $created[] = $email;
            }
        }

        return $created;
    }

    /** An .eml a lawyer uploads to the matter: trusted, since a staff member chose to file it. */
    public function upload(Matter $matter, string $raw, User $by): ?MatterEmail
    {
        return $this->record($matter, $this->parse($raw), $raw, 'upload', $by, true);
    }

    public function accept(MatterEmail $email, User $by): void
    {
        $email->forceFill(['status' => MatterEmail::QUEUED, 'reviewed_by' => $by->id, 'reviewed_at' => now()])->save();
        FileMatterEmail::dispatch($email->id);
    }

    public function reject(MatterEmail $email, User $by): void
    {
        if ($email->raw_path) {
            Storage::disk(MatterFile::disk())->delete($email->raw_path);
        }
        // Keep who sent it and when, not what it said.
        $email->forceFill([
            'status' => MatterEmail::REJECTED, 'reviewed_by' => $by->id, 'reviewed_at' => now(),
            'raw_path' => null, 'body_text' => null, 'attachments' => null,
        ])->save();
    }

    /**
     * Store the message and each attachment as matter files. Safe to run
     * again after a failure (the virus scanner being down): what was filed
     * stays filed and is not duplicated.
     */
    public function file(MatterEmail $email): void
    {
        if ($email->status === MatterEmail::FILED || ! $email->raw_path) {
            return;
        }
        $matter = Matter::findOrFail($email->matter_id);
        $raw = Storage::disk(MatterFile::disk())->get($email->raw_path);
        $by = $this->filer($email, $matter);
        $from = trim(($email->from_name ? "{$email->from_name} " : '')."<{$email->from_email}>");
        $when = $email->sent_at?->timezone(config('app.timezone'))->format('M j, Y g:i A');

        if (! $email->eml_file_id) {
            $name = Str::limit(trim(Str::squish(preg_replace('/[^\pL\pN ._()-]+/u', ' ', (string) $email->subject) ?? '')) ?: 'Email', 120, '').'.eml';
            $file = $this->storeBytes($matter, $raw, $name, 'message/rfc822', $by, "Email from {$from}".($when ? ", {$when}" : ''));
            $email->forceFill(['eml_file_id' => $file->id])->save();
        }

        $attachments = $email->attachments ?? [];
        $parts = $this->attachmentParts($this->parse($raw));
        foreach ($parts as $i => $part) {
            if (($attachments[$i]['file_id'] ?? null) || ($attachments[$i]['skipped'] ?? null)) {
                continue;
            }
            $attachments[$i] = $this->fileAttachment($matter, $part, $by, $email);
            $email->forceFill(['attachments' => $attachments])->save();
        }

        Storage::disk(MatterFile::disk())->delete($email->raw_path);
        $email->forceFill(['status' => MatterEmail::FILED, 'raw_path' => null])->save();
    }

    private function record(Matter $matter, IMessage $message, string $raw, string $source, ?User $uploader, bool $verified): ?MatterEmail
    {
        $messageId = $this->clean($message->getHeaderValue('Message-ID'), 255);
        if ($messageId && MatterEmail::where('matter_id', $matter->id)->where('message_id', $messageId)->exists()) {
            return null; // the same message, copied to the address twice or re-delivered
        }

        $fromHeader = $message->getHeader('From');
        $fromEmail = $this->fromEmail($message);
        // An unconfirmed From line names no one: the email waits for review.
        $sender = $uploader ?? ($fromEmail && $verified ? User::where('firm_id', $matter->firm_id)->where('is_active', true)->whereRaw('lower(email) = ?', [$fromEmail])->first() : null);
        $client = ! $sender && $fromEmail && $verified ? Client::where('id', $matter->client_id)->whereRaw('lower(email) = ?', [$fromEmail])->first() : null;
        $trusted = $sender || $client;

        $path = "firms/{$matter->firm_id}/inbound/".Str::uuid()->toString().'.eml';
        Storage::disk(MatterFile::disk())->put($path, $raw);

        $date = $message->getHeader('Date');
        $email = MatterEmail::create([
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
            'status' => $trusted ? MatterEmail::QUEUED : MatterEmail::REVIEW,
            'source' => $source,
            'message_id' => $messageId,
            'from_email' => $this->clean($fromEmail, 255),
            'from_name' => $fromHeader instanceof AddressHeader ? $this->clean($fromHeader->getPersonName(), 255) : null,
            'to' => implode(', ', $this->addresses($message, ['To'])) ?: null,
            'cc' => implode(', ', $this->addresses($message, ['Cc'])) ?: null,
            'subject' => $this->clean($message->getHeaderValue('Subject'), 500),
            'sent_at' => $date instanceof DateHeader ? $date->getDateTime() : null,
            'body_text' => $this->body($message),
            'attachments' => array_map(fn (IMessagePart $p) => ['name' => $this->attachmentName($p), 'size' => $this->size($p), 'file_id' => null, 'skipped' => null], $this->attachmentParts($message)),
            'raw_path' => $path,
            'sender_user_id' => $sender?->id,
            'sender_client_id' => $client?->id,
        ]);

        if ($trusted) {
            FileMatterEmail::dispatch($email->id);
        } else {
            $this->notifyReview($email, $matter);
        }

        return $email;
    }

    private function notifyReview(MatterEmail $email, Matter $matter): void
    {
        $lawyer = $matter->responsible_lawyer_id ? User::where('is_active', true)->find($matter->responsible_lawyer_id) : null;
        $recipients = $lawyer ? collect([$lawyer]) : User::where('firm_id', $matter->firm_id)->where('is_active', true)->get()->filter(fn (User $u) => $u->role->canManageFirm());
        foreach ($recipients as $user) {
            $user->notify(new EmailNeedsReview($email, $matter));
        }
    }

    /** @return array{file_id: ?int, name: string, size: int, skipped: ?string} */
    private function fileAttachment(Matter $matter, IMessagePart $part, User|Client $by, MatterEmail $email): array
    {
        $name = $this->attachmentName($part);
        $size = $this->size($part);
        $extension = Str::lower(pathinfo($name, PATHINFO_EXTENSION));
        $result = ['name' => $name, 'size' => $size, 'file_id' => null, 'skipped' => null];

        if (! in_array($extension, MatterFile::ALLOWED_TYPES, true)) {
            return [...$result, 'skipped' => 'File type not accepted'];
        }
        if ($size > MatterFile::MAX_KILOBYTES * 1024) {
            return [...$result, 'skipped' => 'Larger than '.(MatterFile::MAX_KILOBYTES / 1024).' MB'];
        }

        try {
            $file = $this->storeBytes($matter, (string) $part->getBinaryContentStream()?->getContents(), $name, $part->getContentType() ?: 'application/octet-stream', $by,
                'Attachment to the email "'.Str::limit((string) $email->subject, 120).'"');
        } catch (ValidationException $e) {
            return [...$result, 'skipped' => collect($e->errors())->flatten()->first() ?: 'Refused'];
        }

        return [...$result, 'file_id' => $file->id];
    }

    private function storeBytes(Matter $matter, string $bytes, string $name, string $mime, User|Client $by, string $description): MatterFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mail');
        file_put_contents($tmp, $bytes);
        try {
            return $this->store->execute($matter, new UploadedFile($tmp, $name, $mime, null, true), $by, Str::limit($description, 490));
        } finally {
            @unlink($tmp);
        }
    }

    /** Filed under whoever sent it, whoever accepted it, or failing both the responsible lawyer. */
    private function filer(MatterEmail $email, Matter $matter): User|Client
    {
        $user = User::find($email->sender_user_id ?? $email->reviewed_by ?? $matter->responsible_lawyer_id);
        if ($user) {
            return $user;
        }
        if ($email->sender_client_id && ($client = Client::find($email->sender_client_id))) {
            return $client;
        }

        return User::where('firm_id', $matter->firm_id)->orderBy('id')->firstOrFail();
    }

    /**
     * Matters whose address appears among the recipients.
     *
     * @param  list<string>  $recipients
     * @return list<Matter>
     */
    private function mattersAddressed(array $recipients): array
    {
        $configured = (string) config('services.inbound_email.address');
        if ($configured === '') {
            return [];
        }
        [$local, $domain] = array_map('strtolower', explode('@', $configured, 2));

        $tokens = [];
        foreach ($recipients as $recipient) {
            $pattern = '/'.preg_quote($local, '/').'\+(?:[a-z0-9-]*\.)?([a-z0-9]{'.self::TOKEN_LENGTH.'})@'.preg_quote($domain, '/').'/i';
            if (preg_match_all($pattern, $recipient, $m)) {
                array_push($tokens, ...array_map('strtolower', $m[1]));
            }
        }

        return $tokens === [] ? [] : Matter::withoutGlobalScopes()->whereIn('inbound_email_token', array_unique($tokens))->get()->all();
    }

    private function fromEmail(IMessage $message): ?string
    {
        $header = $message->getHeader('From');

        return $header instanceof AddressHeader && $header->getEmail() ? Str::lower((string) $header->getEmail()) : null;
    }

    private function parse(string $raw): IMessage
    {
        return (new MailMimeParser)->parse($raw, false);
    }

    /** @return list<string> */
    private function addresses(IMessage $message, array $headers): array
    {
        $out = [];
        foreach ($headers as $name) {
            for ($i = 0; ($header = $message->getHeader($name, $i)) !== null; $i++) {
                if ($header instanceof AddressHeader) {
                    foreach ($header->getAddresses() as $address) {
                        $out[] = Str::lower($address->getEmail());
                    }
                } else {
                    $out[] = Str::lower((string) $header->getValue());
                }
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /**
     * Real attachments, leaving out images embedded in the body (logos in
     * signatures), which are referenced by Content-ID and shown inline.
     *
     * @return list<IMessagePart>
     */
    private function attachmentParts(IMessage $message): array
    {
        return array_values(array_filter(
            $message->getAllAttachmentParts(),
            fn (IMessagePart $p) => ! ($p->getContentId() && Str::startsWith((string) $p->getContentType(), 'image/') && $p->getContentDisposition() !== 'attachment'),
        ));
    }

    private function attachmentName(IMessagePart $part): string
    {
        $name = trim((string) $part->getFilename());
        if ($name === '' && $part->getContentType() === 'message/rfc822') {
            return 'Forwarded message.eml';
        }

        return Str::limit(preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $name) ?: 'attachment', 200, '');
    }

    private function size(IMessagePart $part): int
    {
        return (int) ($part->getBinaryContentStream()?->getSize() ?? 0);
    }

    private function body(IMessage $message): ?string
    {
        $text = $message->getTextContent();
        if ($text === null && ($html = $message->getHtmlContent()) !== null) {
            $text = html_entity_decode(strip_tags(preg_replace(['/<(br|\/p|\/div|\/tr|\/li)[^>]*>/i', '/<(style|script)[^>]*>.*?<\/\1>/is'], ["\n", ''], $html)), ENT_QUOTES | ENT_HTML5);
        }
        if ($text === null) {
            return null;
        }
        $text = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $text)));

        return mb_substr($text, 0, self::BODY_LIMIT);
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** For the webhook: whether a request carries the configured secret. */
    public static function authorized(?string $given): bool
    {
        $secret = (string) config('services.inbound_email.secret');

        try {
            return $secret !== '' && $given !== null && hash_equals($secret, $given);
        } catch (Throwable) {
            return false;
        }
    }
}
