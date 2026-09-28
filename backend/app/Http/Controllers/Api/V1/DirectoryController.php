<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Directory\Models\Contact;
use App\Domain\Directory\Models\Court;
use App\Domain\Directory\Models\MatterContact;
use App\Domain\Matters\Models\Matter;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The firm's directory of courts and people, and who is on which matter. */
class DirectoryController extends Controller
{
    public function courts(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search'));

        return response()->json([
            'levels' => Court::LEVELS,
            'data' => Court::query()
                ->withCount(['matters', 'contacts'])
                ->when($search !== '', fn (Builder $q) => $q->where(fn ($w) => $this->like($w, ['name', 'branch', 'station'], $search)))
                ->orderBy('name')->orderBy('station')->orderBy('branch')->limit(500)->get()
                ->map(fn (Court $c) => $this->presentCourt($c)),
        ]);
    }

    public function court(Court $court): JsonResponse
    {
        $court->loadCount(['matters', 'contacts']);

        return response()->json([
            ...$this->presentCourt($court),
            'contacts' => $court->contacts()->orderByDesc('is_active')->orderBy('name')->get()->map(fn (Contact $c) => $this->presentContact($c)),
            'matters' => $court->matters()->select(['id', 'reference', 'title', 'status'])->latest('id')->limit(100)->get(),
        ]);
    }

    public function storeCourt(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');
        $court = Court::create([...$request->validate($this->courtRules()), 'firm_id' => $request->user()->firm_id]);

        return response()->json($this->presentCourt($court), 201);
    }

    public function updateCourt(Request $request, Court $court): JsonResponse
    {
        Gate::authorize('work-matters');
        $court->update($request->validate($this->courtRules()));

        return response()->json($this->presentCourt($court));
    }

    /** Matters keep the court's name as written; only the link goes. */
    public function destroyCourt(Court $court): JsonResponse
    {
        Gate::authorize('work-matters');
        $court->delete();

        return response()->json(null, 204);
    }

    public function contacts(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::in(array_keys(Contact::KINDS))],
            'court_id' => ['nullable', 'integer'],
            'include_inactive' => ['nullable', 'boolean'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));

        return response()->json([
            'kinds' => Contact::KINDS,
            'roles' => Contact::ROLES,
            'data' => Contact::query()
                ->with('court:id,name,branch,station')
                ->withCount('matterLinks')
                ->when(! ($validated['include_inactive'] ?? false), fn ($q) => $q->where('is_active', true))
                ->when($validated['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
                ->when($validated['court_id'] ?? null, fn ($q, $id) => $q->where('court_id', $id))
                ->when($search !== '', fn (Builder $q) => $q->where(fn ($w) => $this->like($w, ['name', 'organization', 'email'], $search)))
                ->orderBy('name')->limit(500)->get()
                ->map(fn (Contact $c) => $this->presentContact($c)),
        ]);
    }

    public function contact(Contact $contact): JsonResponse
    {
        $contact->load('court:id,name,branch,station')->loadCount('matterLinks');

        return response()->json([
            ...$this->presentContact($contact),
            'matters' => MatterContact::where('contact_id', $contact->id)->with('matter:id,reference,title,status')->latest('id')->limit(100)->get()
                ->map(fn (MatterContact $l) => ['role' => $l->role, 'role_label' => Contact::ROLES[$l->role] ?? $l->role, 'matter' => $l->matter]),
        ]);
    }

    public function storeContact(Request $request): JsonResponse
    {
        Gate::authorize('work-matters');
        $contact = Contact::create([...$request->validate($this->contactRules($request)), 'firm_id' => $request->user()->firm_id]);

        return response()->json($this->presentContact($contact->load('court')), 201);
    }

    public function updateContact(Request $request, Contact $contact): JsonResponse
    {
        Gate::authorize('work-matters');
        $contact->update($request->validate($this->contactRules($request)));

        return response()->json($this->presentContact($contact->load('court')));
    }

    public function destroyContact(Contact $contact): JsonResponse
    {
        Gate::authorize('work-matters');
        // Someone who appeared in a matter stays on record (conflict checks rely on it); retire them instead.
        if (MatterContact::where('contact_id', $contact->id)->exists()) {
            $contact->update(['is_active' => false]);

            return response()->json($this->presentContact($contact->load('court')));
        }
        $contact->delete();

        return response()->json(null, 204);
    }

    public function matterContacts(Matter $matter): JsonResponse
    {
        return response()->json([
            'court' => $matter->court_id ? $this->presentCourt(Court::find($matter->court_id)) : null,
            'contacts' => MatterContact::where('matter_id', $matter->id)->with('contact.court:id,name,branch,station')->orderBy('role')->get()
                ->map(fn (MatterContact $l) => ['id' => $l->id, 'role' => $l->role, 'role_label' => Contact::ROLES[$l->role] ?? $l->role, 'notes' => $l->notes, 'contact' => $this->presentContact($l->contact)]),
        ]);
    }

    public function linkContact(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate([
            'contact_id' => ['required', 'integer', Rule::exists('contacts', 'id')->where('firm_id', $matter->firm_id)],
            'role' => ['required', Rule::in(array_keys(Contact::ROLES))],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $link = MatterContact::firstOrCreate(
            ['matter_id' => $matter->id, 'contact_id' => $validated['contact_id'], 'role' => $validated['role']],
            ['firm_id' => $matter->firm_id, 'notes' => $validated['notes'] ?? null],
        );

        // The presiding judge is also kept on the matter, where pleadings and court day read it.
        if ($validated['role'] === 'judge') {
            $matter->update(['judge' => $link->contact->displayName()]);
        }

        return $this->matterContacts($matter)->setStatusCode($link->wasRecentlyCreated ? 201 : 200);
    }

    public function unlinkContact(MatterContact $matterContact): JsonResponse
    {
        Gate::authorize('work-matters');
        $matterContact->delete();

        return response()->json(null, 204);
    }

    /**
     * Set the matter's court from the directory. The court's name, station
     * and branch are copied to the matter, where captions and court day read
     * them, as is the court's judge when it has one on file.
     */
    public function setCourt(Request $request, Matter $matter): JsonResponse
    {
        Gate::authorize('work-matters');
        $validated = $request->validate(['court_id' => ['nullable', 'integer', Rule::exists('courts', 'id')->where('firm_id', $matter->firm_id)]]);
        $court = isset($validated['court_id']) ? Court::find($validated['court_id']) : null;

        DB::transaction(function () use ($matter, $court) {
            $matter->forceFill(['court_id' => $court?->id])->save();
            if ($court) {
                $judge = Contact::where('court_id', $court->id)->where('kind', 'judge')->where('is_active', true)->latest('id')->first();
                $matter->update(['court' => $court->label(), 'court_branch' => $court->branch, ...($judge ? ['judge' => $judge->displayName()] : [])]);
                if ($judge) {
                    MatterContact::firstOrCreate(['matter_id' => $matter->id, 'contact_id' => $judge->id, 'role' => 'judge'], ['firm_id' => $matter->firm_id]);
                }
            }
        });

        return $this->matterContacts($matter->refresh());
    }

    private function courtRules(): array
    {
        return [
            'level' => ['required', Rule::in(array_keys(Court::LEVELS))],
            'name' => ['required', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:100'],
            'station' => ['nullable', 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function contactRules(Request $request): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(Contact::KINDS))],
            'name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:50'],
            'organization' => ['nullable', 'string', 'max:255'],
            'court_id' => ['nullable', 'integer', Rule::exists('courts', 'id')->where('firm_id', $request->user()->firm_id)],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:500'],
            'roll_number' => ['nullable', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    /** @param  list<string>  $columns */
    private function like(Builder $query, array $columns, string $search): void
    {
        $term = '%'.mb_strtolower($search).'%';
        foreach ($columns as $column) {
            $query->orWhereRaw("lower({$column}) like ?", [$term]);
        }
    }

    private function presentCourt(?Court $c): ?array
    {
        if (! $c) {
            return null;
        }

        return [
            'id' => $c->id, 'level' => $c->level, 'level_label' => Court::LEVELS[$c->level] ?? $c->level, 'name' => $c->name, 'branch' => $c->branch,
            'station' => $c->station, 'label' => $c->label(), 'address' => $c->address, 'email' => $c->email, 'phone' => $c->phone, 'notes' => $c->notes,
            'matters_count' => isset($c->matters_count) ? (int) $c->matters_count : null, 'contacts_count' => isset($c->contacts_count) ? (int) $c->contacts_count : null,
        ];
    }

    private function presentContact(Contact $c): array
    {
        return [
            'id' => $c->id, 'kind' => $c->kind, 'kind_label' => Contact::KINDS[$c->kind] ?? $c->kind, 'name' => $c->name, 'title' => $c->title,
            'display_name' => $c->displayName(), 'organization' => $c->organization, 'court_id' => $c->court_id,
            'court' => $c->relationLoaded('court') && $c->court ? trim($c->court->label().($c->court->branch ? ", {$c->court->branch}" : '')) : null,
            'email' => $c->email, 'phone' => $c->phone, 'address' => $c->address, 'roll_number' => $c->roll_number, 'notes' => $c->notes,
            'is_active' => $c->is_active, 'matters_count' => isset($c->matter_links_count) ? (int) $c->matter_links_count : null,
        ];
    }
}
