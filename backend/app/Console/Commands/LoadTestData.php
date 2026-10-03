<?php

namespace App\Console\Commands;

use App\Domain\Matters\Models\Firm;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fills one firm with years of realistic volume, to see how the system
 * performs before a busy firm finds out: by default 300 clients, 500
 * matters, 10,000 deadlines, 50,000 time entries, 3,000 invoices, 20,000
 * files with searchable text, 3,000 documents and 100,000 audit entries.
 *
 * Rows are inserted directly in bulk (no events, no reminders sent), for a
 * throwaway copy of the system. It refuses to run in production unless
 * told to with --force.
 */
class LoadTestData extends Command
{
    protected $signature = 'ops:load-test-data {--firm=1 : The firm to fill} {--scale=1 : Multiplies every volume} {--force : Run even with APP_ENV=production}';

    protected $description = 'Insert years of realistic data into one firm, for load testing a throwaway copy';

    private const WORDS = ['complaint', 'answer', 'motion', 'hearing', 'evidence', 'witness', 'affidavit', 'contract', 'payment', 'demand', 'damages', 'negligence',
        'property', 'title', 'possession', 'ejectment', 'collection', 'sum', 'money', 'annulment', 'custody', 'support', 'estate', 'probate', 'labor', 'dismissal',
        'wages', 'corporation', 'board', 'resolution', 'shares', 'tax', 'assessment', 'appeal', 'decision', 'order', 'court', 'branch', 'judge', 'counsel',
        'plaintiff', 'defendant', 'respondent', 'petitioner', 'notice', 'summons', 'service', 'jurisdiction', 'prescription', 'laches', 'estoppel', 'mediation'];

    private const SURNAMES = ['Santos', 'Reyes', 'Cruz', 'Bautista', 'Ocampo', 'Garcia', 'Mendoza', 'Torres', 'Tomas', 'Andrada', 'Castillo', 'Flores', 'Villanueva',
        'Ramos', 'Castro', 'Rivera', 'Aquino', 'Navarro', 'Salazar', 'Mercado', 'Domingo', 'Gonzales', 'Lopez', 'Dela Cruz', 'De Leon', 'Pascual', 'Soriano'];

    private const GIVEN = ['Juan', 'Maria', 'Jose', 'Ana', 'Pedro', 'Rosa', 'Antonio', 'Carmen', 'Ramon', 'Teresa', 'Miguel', 'Lourdes', 'Carlos', 'Elena', 'Ricardo'];

    public function handle(TenantContext $tenant): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('This inserts large amounts of made-up data. Run it only on a throwaway copy, with --force.');

            return self::FAILURE;
        }

        $firm = Firm::findOrFail((int) $this->option('firm'));
        $scale = max(1, (int) $this->option('scale'));
        mt_srand(20261003);

        return $tenant->runAs($firm->id, function () use ($firm, $scale) {
            $users = User::where('firm_id', $firm->id)->pluck('id')->all();
            if ($users === []) {
                $this->error('The firm has no users; seed the demo first.');

                return self::FAILURE;
            }
            $started = microtime(true);
            $now = CarbonImmutable::now();

            $clientIds = $this->insert('clients', 300 * $scale, fn (int $i) => [
                'firm_id' => $firm->id,
                'type' => $i % 4 === 0 ? 'corporate' : 'individual',
                'name' => $i % 4 === 0 ? $this->pick(self::SURNAMES).' '.$this->pick(['Holdings', 'Trading', 'Realty', 'Shipping', 'Foods']).' Corp. '.$i : $this->pick(self::GIVEN).' '.$this->pick(self::SURNAMES).' '.$i,
                'email' => "loadtest-client{$i}@example.com",
                'created_at' => $now->subDays(mt_rand(0, 1800)), 'updated_at' => $now,
            ]);

            $statuses = ['intake', 'filed', 'pre_trial', 'trial', 'decision', 'appeal', 'closed', 'closed', 'closed'];
            $caseTypes = ['Civil', 'Criminal', 'Labor', 'Family', 'Corporate', 'Tax', 'Special Proceedings'];
            $matterIds = $this->insert('matters', 500 * $scale, fn (int $i) => [
                'firm_id' => $firm->id,
                'client_id' => $clientIds[$i % count($clientIds)],
                'responsible_lawyer_id' => $this->pick($users),
                'reference' => sprintf('LT-%05d', $i),
                'title' => $this->pick(self::SURNAMES).' v. '.$this->pick(self::SURNAMES).' '.$i,
                'case_type' => $this->pick($caseTypes),
                'case_number' => 'R-MNL-'.mt_rand(18, 26).'-'.sprintf('%05d', $i).'-CV',
                'court' => 'Regional Trial Court, '.$this->pick(['Manila', 'Makati City', 'Quezon City', 'Pasig City', 'Cebu City']),
                'status' => $this->pick($statuses),
                'description' => $this->sentence(25),
                'opened_at' => $now->subDays(mt_rand(0, 1800))->toDateString(),
                'created_at' => $now->subDays(mt_rand(0, 1800)), 'updated_at' => $now,
            ]);

            $this->insert('matter_parties', 1000 * $scale, fn (int $i) => [
                'matter_id' => $matterIds[$i % count($matterIds)],
                'role' => $i % 3 === 0 ? 'adverse_counsel' : 'adverse_party',
                'name' => $this->pick(self::GIVEN).' '.$this->pick(self::SURNAMES),
                'created_at' => $now, 'updated_at' => $now,
            ], ids: false);

            $this->insert('matter_deadlines', 10000 * $scale, function (int $i) use ($firm, $matterIds, $users, $now) {
                $due = $now->addDays(mt_rand(-1500, 120));
                $past = $due->lt($now);

                return [
                    'firm_id' => $firm->id,
                    'matter_id' => $matterIds[$i % count($matterIds)],
                    'assigned_to' => $this->pick($users),
                    'kind' => $this->pick(['filing', 'filing', 'hearing', 'task']),
                    'title' => ucfirst($this->pick(self::WORDS)).' '.$this->pick(self::WORDS),
                    'due_date' => $due->toDateString(),
                    'status' => $past ? ($i % 20 === 0 ? 'missed' : 'completed') : 'pending',
                    'created_at' => $due->subDays(15), 'updated_at' => $now,
                ];
            }, ids: false);

            $this->insert('time_entries', 50000 * $scale, function (int $i) use ($firm, $matterIds, $users, $now) {
                $minutes = mt_rand(1, 24) * 15;
                $rate = $this->pick([350000, 500000, 750000, 1000000]);

                return [
                    'firm_id' => $firm->id,
                    'matter_id' => $matterIds[$i % count($matterIds)],
                    'user_id' => $this->pick($users),
                    'work_date' => $now->subDays(mt_rand(0, 1800))->toDateString(),
                    'minutes' => $minutes,
                    'rate_cents' => $rate,
                    'amount_cents' => intdiv($minutes * $rate, 60),
                    'description' => ucfirst($this->sentence(8)),
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }, ids: false);

            $this->insert('invoices', 3000 * $scale, function (int $i) use ($firm, $matterIds, $now) {
                $subtotal = mt_rand(20, 400) * 100000;
                $vat = intdiv($subtotal * 12, 100);
                $issued = $now->subDays(mt_rand(0, 1800));
                $paid = $i % 5 !== 0;
                $matter = $matterIds[$i % count($matterIds)];

                return [
                    'firm_id' => $firm->id,
                    'client_id' => DB::table('matters')->where('id', $matter)->value('client_id'),
                    'matter_id' => $matter,
                    'number' => sprintf('LT-INV-%06d', $i),
                    'status' => $paid ? 'paid' : 'issued',
                    'subtotal_cents' => $subtotal, 'vat_cents' => $vat, 'total_cents' => $subtotal + $vat,
                    'settled_cents' => $paid ? $subtotal + $vat : 0,
                    'issued_at' => $issued->toDateString(),
                    'due_at' => $issued->addDays(30)->toDateString(),
                    'paid_at' => $paid ? $issued->addDays(mt_rand(5, 90))->toDateString() : null,
                    'created_at' => $issued, 'updated_at' => $now,
                ];
            }, ids: false);

            $this->insert('matter_files', 20000 * $scale, fn (int $i) => [
                'firm_id' => $firm->id,
                'matter_id' => $matterIds[$i % count($matterIds)],
                'uploaded_by' => $this->pick($users),
                'original_name' => ucfirst($this->pick(self::WORDS)).'-'.$this->pick(self::WORDS)."-{$i}.pdf",
                'path' => "loadtest/{$i}.pdf",
                'mime_type' => 'application/pdf',
                'size_bytes' => mt_rand(20000, 5000000),
                'sha256' => hash('sha256', "file{$i}"),
                'scan_status' => 'clean',
                'text_status' => 'extracted',
                'text_source' => 'text',
                'content_text' => $this->sentence(350),
                'created_at' => $now->subDays(mt_rand(0, 1800)), 'updated_at' => $now,
            ], ids: false);

            $documentIds = $this->insert('documents', 3000 * $scale, fn (int $i) => [
                'firm_id' => $firm->id,
                'matter_id' => $matterIds[$i % count($matterIds)],
                'title' => ucfirst($this->pick(self::WORDS)).' '.$this->pick(['Motion', 'Answer', 'Position Paper', 'Demand Letter', 'Affidavit'])." {$i}",
                'status' => $this->pick(['draft', 'final', 'signed']),
                'current_version' => 1,
                'created_by' => $this->pick($users),
                'created_at' => $now->subDays(mt_rand(0, 1800)), 'updated_at' => $now,
            ]);
            $this->insert('document_versions', count($documentIds), fn (int $i) => [
                'document_id' => $documentIds[$i - 1],
                'version_number' => 1,
                'content' => $this->sentence(600),
                'created_by' => $this->pick($users),
                'created_at' => $now,
            ], ids: false);

            $this->insert('audit_logs', 100000 * $scale, fn (int $i) => [
                'firm_id' => $firm->id,
                'actor_type' => 'user',
                'actor_id' => $this->pick($users),
                'action' => $this->pick(['created', 'updated', 'updated', 'viewed']),
                'subject_type' => 'matter',
                'subject_id' => $matterIds[$i % count($matterIds)],
                'changes' => json_encode(['after' => ['status' => 'filed']]),
                'created_at' => $now->subMinutes(mt_rand(0, 2600000)),
            ], ids: false);

            $this->insert('knowledge_items', 500 * $scale, fn (int $i) => [
                'firm_id' => $firm->id,
                'kind' => $this->pick(['pleading', 'clause', 'jurisprudence', 'note']),
                'title' => ucfirst($this->sentence(5)),
                'citation' => $i % 3 === 0 ? 'G.R. No. '.mt_rand(100000, 270000) : null,
                'doctrine' => $this->sentence(30),
                'body' => $this->sentence(400),
                'practice_area' => $this->pick($caseTypes),
                'created_at' => $now, 'updated_at' => $now,
            ], ids: false);

            $this->info(sprintf('Done in %.0f s.', microtime(true) - $started));
            DB::statement('ANALYZE');

            return self::SUCCESS;
        });
    }

    /**
     * Insert $count rows in chunks; returns the new ids when asked.
     *
     * @return list<int>
     */
    private function insert(string $table, int $count, callable $row, bool $ids = true): array
    {
        $this->line("  {$table}: {$count}");
        $chunk = 500;
        $created = [];
        $before = $ids ? (int) DB::table($table)->max('id') : 0;
        for ($start = 1; $start <= $count; $start += $chunk) {
            $rows = [];
            for ($i = $start; $i < min($start + $chunk, $count + 1); $i++) {
                $rows[] = $row($i);
            }
            DB::table($table)->insert($rows);
        }
        if ($ids) {
            $created = DB::table($table)->where('id', '>', $before)->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return $created;
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    private function sentence(int $words): string
    {
        $out = [];
        for ($i = 0; $i < $words; $i++) {
            $out[] = self::WORDS[mt_rand(0, count(self::WORDS) - 1)];
        }

        return Str::of(implode(' ', $out))->toString().'.';
    }
}
