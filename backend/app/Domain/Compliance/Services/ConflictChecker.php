<?php

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Domain\Compliance\Models\ConflictCheck;
use App\Domain\Directory\Models\MatterContact;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Domain\Matters\Models\MatterParty;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Searches the firm's clients and every party to its matters for a
 * prospective client or adverse party, and records the search.
 *
 * Matching (NameMatcher) ignores word order, punctuation, honorifics and
 * company forms, joins Filipino name particles, and tolerates small
 * misspellings, so "Juan de la Cruz" finds "DELA CRUZ, Juan" and
 * "Ramon Fernandes" finds "Ramon Fernandez". Clients are also found by
 * their other names (maiden, former and trade names).
 */
class ConflictChecker
{
    public function __construct(private readonly NameMatcher $matcher) {}

    /** $requestedBy is null for automatic checks, e.g. on an online intake request. */
    public function check(int $firmId, string $searchTerm, ?User $requestedBy): ConflictCheck
    {
        if ($this->matcher->tokens($searchTerm) === []) {
            throw ValidationException::withMessages(['name' => 'Enter a name with at least two letters.']);
        }

        // Strongest first; a long list is trimmed, the count says how many.
        $matches = collect([...$this->matchingClients($firmId, $searchTerm), ...$this->matchingParties($firmId, $searchTerm), ...$this->matchingCounsel($firmId, $searchTerm)])
            ->sortByDesc('score')->values()->take(100)->all();

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

    /** Clients (former ones included), by their name and every other name they are known by. */
    private function matchingClients(int $firmId, string $term): array
    {
        $matches = [];
        Client::withoutGlobalScopes()->withTrashed()->where('firm_id', $firmId)
            ->select(['id', 'name', 'aliases', 'deleted_at'])->withCount('matters')
            ->chunkById(500, function ($clients) use ($term, &$matches) {
                foreach ($clients as $client) {
                    $best = null;
                    foreach ([$client->name, ...$this->aliases($client->aliases)] as $i => $name) {
                        $result = $this->matcher->compare($term, $name);
                        if ($result && (! $best || $result['score'] > $best['score'])) {
                            $best = [...$result, 'via' => $i === 0 ? null : $name];
                        }
                    }
                    if ($best) {
                        $matches[] = [
                            'source' => 'client',
                            'id' => $client->id,
                            'name' => $client->name,
                            'relationship' => $client->trashed() ? 'Former client' : 'Client',
                            'is_adverse' => false,
                            'matter_id' => null,
                            'matter_reference' => null,
                            'matter_title' => "{$client->matters_count} matter(s)",
                            'score' => $best['score'],
                            'reason' => $best['reason'].($best['via'] ? " (also known as {$best['via']})" : ''),
                        ];
                    }
                }
            });

        return $matches;
    }

    /** Every party to every matter, including closed and deleted ones. */
    private function matchingParties(int $firmId, string $term): array
    {
        $matches = [];
        MatterParty::query()
            ->whereHas('matter', fn (Builder $q) => $q->withoutGlobalScopes()->withTrashed()->where('firm_id', $firmId))
            ->with(['matter' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()])
            ->chunkById(500, function ($parties) use ($term, &$matches) {
                foreach ($parties as $party) {
                    $result = $this->matcher->compare($term, $party->name);
                    if ($result) {
                        $matches[] = [
                            'source' => 'party',
                            'id' => $party->id,
                            'name' => $party->name,
                            'relationship' => $party->role->label(),
                            'is_adverse' => $party->role->isAdverse(),
                            'matter_id' => $party->matter?->id,
                            'matter_reference' => $party->matter?->reference,
                            'matter_title' => $party->matter?->title,
                            'score' => $result['score'],
                            'reason' => $result['reason'],
                        ];
                    }
                }
            });

        return $matches;
    }

    /**
     * Opposing counsel: named on a matter's adverse party, or linked to a
     * matter from the directory (including counsel who have since retired).
     */
    private function matchingCounsel(int $firmId, string $term): array
    {
        $matches = [];
        $add = function (string $name, string $source, int $id, ?Matter $matter, string $reason, int $score) use (&$matches) {
            $matches[] = [
                'source' => $source,
                'id' => $id,
                'name' => $name,
                'relationship' => 'Opposing counsel',
                'is_adverse' => true,
                'matter_id' => $matter?->id,
                'matter_reference' => $matter?->reference,
                'matter_title' => $matter?->title,
                'score' => $score,
                'reason' => $reason,
            ];
        };

        MatterParty::query()
            ->whereNotNull('counsel_name')
            ->whereHas('matter', fn (Builder $q) => $q->withoutGlobalScopes()->withTrashed()->where('firm_id', $firmId))
            ->with(['matter' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()])
            ->chunkById(500, function ($parties) use ($term, $add) {
                foreach ($parties as $party) {
                    if ($result = $this->matcher->compare($term, (string) $party->counsel_name)) {
                        $add((string) $party->counsel_name, 'party_counsel', $party->id, $party->matter, $result['reason']." (counsel for {$party->name})", $result['score']);
                    }
                }
            });

        MatterContact::withoutGlobalScopes()->where('firm_id', $firmId)->where('role', 'opposing_counsel')
            ->with(['contact' => fn ($q) => $q->withoutGlobalScopes(), 'matter' => fn ($q) => $q->withoutGlobalScopes()->withTrashed()])
            ->chunkById(500, function ($links) use ($term, $add) {
                foreach ($links as $link) {
                    $name = (string) $link->contact?->name;
                    if ($name !== '' && ($result = $this->matcher->compare($term, $name))) {
                        $add($name, 'directory', $link->contact_id, $link->matter, $result['reason'].($link->contact->organization ? " ({$link->contact->organization})" : ''), $result['score']);
                    }
                }
            });

        return $matches;
    }

    /** @return list<string> */
    private function aliases(?string $aliases): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\r\n;]+/', (string) $aliases) ?: [])));
    }
}
