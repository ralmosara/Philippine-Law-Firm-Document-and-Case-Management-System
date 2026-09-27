<?php

namespace App\Domain\Matters\Services;

use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Notifications\PortalPasswordLink;
use App\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Password reset (and first-time setup) links for the client portal.
 *
 * Tokens are per client, not per email address, because one person can be
 * a client of several firms: each firm's portal account gets its own link.
 * Only an HMAC of the token is stored.
 */
class ClientPasswordResets
{
    private const TABLE = 'client_password_reset_tokens';

    public const EXPIRE_MINUTES = 60;

    /** An invitation to set a first password stays valid for a week. */
    public const INVITE_EXPIRE_MINUTES = 7 * 24 * 60;

    private const THROTTLE_SECONDS = 60;

    /** Email a reset link for every portal account with this address. */
    public function sendResetLinks(string $email): void
    {
        Client::withoutGlobalScopes()
            ->where('email', $email)
            ->where('portal_enabled', true)
            ->get()
            ->each(fn (Client $client) => $this->send($client, invite: false));
    }

    /** Email a first-time setup link when the firm opens a client's portal account. */
    public function sendInvite(Client $client): bool
    {
        return $this->send($client, invite: true);
    }

    public function reset(int $clientId, string $token, string $password): bool
    {
        $row = DB::table(self::TABLE)->where('client_id', $clientId)->first();

        if ($row === null || ! hash_equals($row->token, $this->hash($token))) {
            return false;
        }

        $client = Client::withoutGlobalScopes()->find($clientId);
        $lifetime = $client?->password === null ? self::INVITE_EXPIRE_MINUTES : self::EXPIRE_MINUTES;

        if ($client === null || ! $client->portal_enabled || Carbon::parse($row->created_at)->addMinutes($lifetime)->isPast()) {
            return false;
        }

        DB::transaction(function () use ($client, $password) {
            $client->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            DB::table(self::TABLE)->where('client_id', $client->id)->delete();
            AuditLog::record('portal_password_reset', $client->firm_id, $client, $client);
        });

        return true;
    }

    private function send(Client $client, bool $invite): bool
    {
        $existing = DB::table(self::TABLE)->where('client_id', $client->id)->value('created_at');

        if ($existing !== null && Carbon::parse($existing)->addSeconds(self::THROTTLE_SECONDS)->isFuture()) {
            return false;
        }

        $token = Str::random(64);
        DB::table(self::TABLE)->upsert(
            ['client_id' => $client->id, 'token' => $this->hash($token), 'created_at' => now()],
            ['client_id'],
            ['token', 'created_at'],
        );

        $firmName = Firm::whereKey($client->firm_id)->value('name');
        $client->notify(new PortalPasswordLink($token, $client->id, (string) $firmName, $invite));

        return true;
    }

    private function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }
}
