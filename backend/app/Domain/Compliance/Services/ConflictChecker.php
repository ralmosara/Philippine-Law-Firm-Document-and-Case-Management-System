<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\MatterParty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Searches the firm's clients and every party to its matters for a
 * prospective client or adverse party, and records the search.
 *
 * Matching is token-based and order-insensitive, so "Juan Dela Cruz" finds
 * "DELA CRUZ, Juan" and "Dela Cruz Holdings, Inc." alike.
 */
class ConflictChecker
{
    /** $requestedBy is null for automatic checks, e.g. on an online intake request. */
    public function check(int $firmId, string $searchTerm, ?User $requestedBy): ConflictCheck
    {
        $tokens = $this->tokens($searchTerm);

        if ($tokens === []) {
            throw ValidationException::withMessages(['name' => 'Enter a name with at least two letters.']);
        }

        $matches = [...$this->matchingClients($firmId, $tokens), ...$this->matchingParties($firmId, $tokens)];

        return ConflictCheck::create([
            'firm_id' => $firmId,
            'requested_by' => $requestedBy?->id,
            'search_term' => trim($searchTerm),
            'matches' => $matches,
            'match_count' => count($matches),
            'status' => $matches === [] ? ConflictCheckStatus::Clear : ConflictCheckStatus::Flagged,
        ]);
    }

    /** Record a lawyer's decision on a flagged check. */
    public function resolve(ConflictCheck $check, ConflictCheckStatus $decision, string $notes, User $by): ConflictCheck
    {
        if ($check->status !== ConflictCheckStatus::Flagged) {
            throw ValidationException::withMessages(['status' => 'Only flagged checks need a resolution.']);
        }
        if (! in_array($decision, [ConflictCheckStatus::Waived, ConflictCheckStatus::Declined], true)) {
            throw ValidationException::withMessages(['status' => 'Resolve a check as waived or declined.']);
        }

        $check->forceFill([
            'status' => $decision,
            'resolved_by' => $by->id,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ])->save();

        return $check;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $term): array
    {
        return collect(preg_split('/[^\pL\pN]+/u', Str::lower($term)) ?: [])
            ->filter(fn (string $token) => mb_strlen($token) >= 2)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $tokens
     */
    private function whereAllTokens(Builder $query, string $column, array $tokens): Builder
    {
        foreach ($tokens as $token) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $token);
            $query->whereRaw("LOWER({$column}) LIKE ? ESCAPE '\\'", ["%{$escaped}%"]);
        }

        return $query;
    }

    private function matchingClients(int $firmId, array $tokens): array
    {
        $query = Client::withoutGlobalScopes()->withTrashed()->where('firm_id', $firmId);

        return $this->whereAllTokens($query, 'name', $tokens)
            ->withCount('matters')
            ->limit(50)
            ->get()
            ->map(fn (Client $client) => [
                'source' => 'client',
                'id' => $client->id,
                'name' => $client->name,
                'relationship' => $client->trashed() ? 'Former client' : 'Client',
                'is_adverse' => false,
                'matter_id' => null,
                'matter_reference' => null,
                'matter_title' => "{$client->matters_count} matter(s)",
            ])
            ->all();
    }

    private function matchingParties(int $firmId, array $tokens): array
    {
        $query = MatterParty::query()
            ->whereHas('matter', fn (Builder $q) => $q->withoutGlobalScopes()->where('firm_id', $firmId));

        return $this->whereAllTokens($query, 'name', $tokens)
            ->with(['matter' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()])
            ->limit(50)
            ->get()
            ->map(fn (MatterParty $party) => [
                'source' => 'party',
                'id' => $party->id,
                'name' => $party->name,
                'relationship' => $party->role->label(),
                'is_adverse' => $party->role->isAdverse(),
                'matter_id' => $party->matter?->id,
                'matter_reference' => $party->matter?->reference,
                'matter_title' => $party->matter?->title,
            ])
            ->all();
    }
}
