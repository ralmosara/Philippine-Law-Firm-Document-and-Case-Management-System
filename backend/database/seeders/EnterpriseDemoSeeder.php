<?php

namespace Database\Seeders;

use App\Domain\Billing\Models\Expense;
use App\Domain\Billing\Models\TimeEntry;
use App\Domain\Billing\Services\InvoiceGenerator;
use App\Domain\Compliance\Models\McleCompliancePeriod;
use App\Domain\Compliance\Models\McleCredit;
use App\Domain\Compliance\Services\ConflictChecker;
use App\Domain\Deadlines\Enums\DeadlineKind;
use App\Domain\Deadlines\Models\DeadlineRule;
use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Deadlines\Services\DeadlineScheduler;
use App\Domain\Documents\Models\DocumentTemplate;
use App\Domain\Documents\Models\NotarialEntry;
use App\Domain\Documents\Services\DocumentMerger;
use App\Domain\Intake\Services\IntakeService;
use App\Domain\Matters\Actions\OpenMatter;
use App\Domain\Matters\Actions\TransitionMatterStatus;
use App\Domain\Matters\Enums\MatterStatus;
use App\Domain\Matters\Models\CaseWorkflowTemplate;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Messaging\Services\Messaging;
use App\Domain\Trust\Models\TrustAccount;
use App\Domain\Trust\Services\TrustLedgerService;
use App\Enums\Role;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A realistic demo firm, built through the same domain services the API
 * uses so every ledger, invoice and history is internally consistent.
 *
 * Logins (password: "password"):
 *   ceo@demofirm.ph        Managing Partner
 *   partner@demofirm.ph    Partner
 *   lawyer1@demofirm.ph    Associate (also lawyer2, lawyer3)
 *   paralegal@demofirm.ph  Paralegal
 *   client1@corporate.com  Client portal
 */
class EnterpriseDemoSeeder extends Seeder
{
    /*
     * Curated, deterministic demo data (no Faker: it is a dev-only dependency,
     * and the demo must also seed inside production images).
     */
    private const PEOPLE = [
        'Juan Miguel Dela Cruz', 'Maria Theresa Villanueva', 'Ramon Castillo Aquino', 'Cristina Mae Bautista', 'Eduardo Salazar Ramos',
        'Josefina Garcia Mendoza', 'Rogelio Navarro Torres', 'Liza Marie Fernandez', 'Antonio Pascual Gonzales', 'Rosario Dizon Santiago',
        'Fernando Aguilar Domingo', 'Carmelita Reyes Soriano', 'Benjamin Cruz Manalo', 'Angelica Sison Tolentino', 'Ricardo Lim Tiu',
        'Imelda Robles Castro', 'Gregorio Ocampo Valdez', 'Teresita Uy Chua', 'Danilo Morales Padilla', 'Marites Evangelista Rivera',
        'Alfredo Samonte Ignacio', 'Corazon Macaraeg Flores', 'Victor Hilario Salcedo', 'Evelyn Tan Go', 'Rolando Abad Cabrera',
    ];

    private const COMPANIES = [
        'Kalayaan Realty Development Corp.', 'Bagong Silang Agri-Ventures, Inc.', 'Luzon Logistics & Freight Corp.', 'Pacific Harbor Holdings, Inc.',
        'Mindanao Coco Products Corp.', 'Visayas Renewable Energy, Inc.', 'Intramuros Heritage Hotels Corp.', 'Tanglaw Software Solutions, Inc.',
    ];

    private const LAW_FIRMS = ['Villaraza & Partners', 'Castillo Laman Tan', 'Ocampo Reyes Law Offices', 'Sycip Salazar Law', 'Poblador Bautista & Associates'];

    private const ADDRESSES = [
        '12 Kamias Road, Quezon City', '45 Dela Rosa Street, Legaspi Village, Makati City', 'Unit 8B, One Corporate Center, Ortigas, Pasig City',
        '221 Mabini Street, Ermita, Manila', '7 Acacia Lane, Ayala Alabang, Muntinlupa City', 'IT Park, Lahug, Cebu City', 'J.P. Laurel Avenue, Davao City',
    ];

    private const DESCRIPTIONS = [
        'Civil' => 'Collection of sum of money with damages arising from breach of a supply contract.',
        'Labor' => 'Illegal dismissal complaint with claims for backwages and separation pay.',
        'Corporate' => 'Intra-corporate dispute over the validity of the annual stockholders’ meeting.',
        'Criminal' => 'Estafa under Art. 315 of the Revised Penal Code; client is the private complainant.',
        'Annulment' => 'Petition for declaration of nullity of marriage under Art. 36 of the Family Code.',
        'Estate / Probate' => 'Extrajudicial settlement and partition of the estate of the late spouses.',
    ];

    private int $sequence = 0;

    private function next(array $list): string
    {
        return $list[$this->sequence++ % count($list)];
    }

    private function person(): string
    {
        return $this->next(self::PEOPLE);
    }

    private function company(): string
    {
        return $this->next(self::COMPANIES);
    }

    private function lawFirm(): string
    {
        return $this->next(self::LAW_FIRMS);
    }

    private function address(): string
    {
        return $this->next(self::ADDRESSES);
    }

    /** Deterministic pseudo-random digits. */
    private function digits(int $length): string
    {
        // Digits of an md5 hash: stable, and free of crc32()'s sign issues on 32-bit PHP.
        return substr(preg_replace('/\D/', '', md5('lexph'.$this->sequence++)).str_repeat('7', $length), 0, $length);
    }

    public function run(
        TenantContext $tenant,
        OpenMatter $openMatter,
        TransitionMatterStatus $transition,
        DeadlineScheduler $scheduler,
        TrustLedgerService $ledger,
        InvoiceGenerator $invoices,
        DocumentMerger $merger,
        ConflictChecker $conflicts,
    ): void {
        if (Firm::where('name', 'Demo Law Partners')->exists()) {
            $this->command?->warn('Demo firm already seeded; skipping.');

            return;
        }

        $today = CarbonImmutable::today();

        DB::transaction(function () use ($tenant, $openMatter, $transition, $scheduler, $ledger, $invoices, $merger, $conflicts, $today) {
            $firm = Firm::create([
                'name' => 'Demo Law Partners',
                'tin' => '009-876-543-000',
                'address' => '18/F Ayala Tower One, Ayala Avenue, Makati City',
                'email' => 'info@demofirm.ph',
                'phone' => '+63288881234',
                'vat_registered' => true,
            ]);

            $ceo = $this->user($firm, 'Atty. Maria Clara Santos', 'ceo@demofirm.ph', Role::ManagingPartner, 800000, '09171234567');

            $tenant->runAs($firm->id, function () use ($firm, $ceo, $openMatter, $transition, $scheduler, $ledger, $invoices, $merger, $conflicts, $today) {
                $partner = $this->user($firm, 'Atty. Jose Rizal Mercado', 'partner@demofirm.ph', Role::Partner, 650000);
                $associates = collect([
                    $this->user($firm, 'Atty. Andres Bonifacio Cruz', 'lawyer1@demofirm.ph', Role::Associate, 400000),
                    $this->user($firm, 'Atty. Gabriela Silang Reyes', 'lawyer2@demofirm.ph', Role::Associate, 350000),
                    $this->user($firm, 'Atty. Apolinario Mabini Tan', 'lawyer3@demofirm.ph', Role::Associate, 350000),
                ]);
                $paralegal = $this->user($firm, 'Emilio Jacinto Lopez', 'paralegal@demofirm.ph', Role::Paralegal, 150000);
                $lawyers = $associates->concat([$partner, $ceo]);

                $this->templates();
                CaseWorkflowTemplate::create([
                    'case_type' => 'Annulment',
                    'name' => 'Annulment / Declaration of Nullity checklist',
                    'tasks' => [
                        ['title' => 'Schedule psychological evaluation', 'days_offset' => 7, 'kind' => 'task'],
                        ['title' => 'Draft petition', 'days_offset' => 21, 'kind' => 'task'],
                        ['title' => 'Secure PSA marriage & birth certificates', 'days_offset' => 14, 'kind' => 'task'],
                    ],
                ]);

                // Clients: the first is the portal demo account.
                $clients = collect(range(1, 12))->map(fn (int $i) => Client::create([
                    'type' => $i % 3 === 0 ? 'individual' : 'corporate',
                    'name' => $i % 3 === 0 ? $this->person() : $this->company(),
                    'email' => "client{$i}@corporate.com",
                    'phone' => '09'.$this->digits(9),
                    'address' => $this->address(),
                    'tin' => $this->digits(3).'-'.$this->digits(3).'-'.$this->digits(3).'-000',
                    'portal_enabled' => $i === 1,
                    'password' => $i === 1 ? 'password' : null,
                ]));

                $caseTypes = ['Civil', 'Labor', 'Corporate', 'Criminal', 'Annulment', 'Estate / Probate'];
                $courts = ['Regional Trial Court, Branch 58, Makati City', 'Regional Trial Court, Branch 99, Quezon City', 'Metropolitan Trial Court, Branch 3, Pasig City', 'NLRC NCR Arbitration Branch', 'Court of Appeals, Manila'];
                $paths = [
                    [MatterStatus::Filed, MatterStatus::PreTrial, MatterStatus::Trial],
                    [MatterStatus::Filed, MatterStatus::PreTrial],
                    [MatterStatus::Filed],
                    [],
                    [MatterStatus::Filed, MatterStatus::Decision, MatterStatus::Appeal],
                    [MatterStatus::Filed, MatterStatus::Decision, MatterStatus::Closed],
                ];

                $matters = collect();
                foreach ($clients as $index => $client) {
                    foreach (range(1, $index < 4 ? 3 : 1) as $n) {
                        $lawyer = $lawyers[($index + $n) % $lawyers->count()];
                        $caseType = $caseTypes[($index + $n) % count($caseTypes)];
                        $opponent = $this->person();

                        $matter = $openMatter->execute([
                            'client_id' => $client->id,
                            'responsible_lawyer_id' => $lawyer->id,
                            'title' => ($client->type === 'corporate' ? $client->name : Str::afterLast($client->name, ' ')).' v. '.Str::afterLast($opponent, ' '),
                            'case_type' => $caseType,
                            'court' => $courts[($index + $n) % count($courts)],
                            'description' => self::DESCRIPTIONS[$caseType] ?? null,
                            'opened_at' => $today->subDays(30 + ($index * 23 + $n * 11) % 300),
                        ], $lawyer, [
                            ['role' => 'adverse_party', 'name' => $opponent],
                            ['role' => 'adverse_counsel', 'name' => 'Atty. '.$this->person(), 'counsel_name' => $this->lawFirm()],
                        ]);

                        foreach ($paths[($index + $n) % count($paths)] as $status) {
                            if ($status === MatterStatus::Filed) {
                                $matter->forceFill(['case_number' => 'CV-'.$today->year.'-'.$this->digits(4)])->save();
                            }
                            $transition->execute($matter, $status, $lawyer, $status === MatterStatus::Closed ? 'Judgment satisfied; case terminated.' : null);
                        }

                        $matters->push($matter->refresh());
                    }
                }

                $this->deadlines($matters, $scheduler, $ceo, $paralegal, $today);
                $this->time($matters, $lawyers->concat([$paralegal]), $today);
                $this->money($matters, $clients, $ledger, $invoices, $ceo, $today);

                // Documents from templates for the portal client's first matter.
                $portalMatter = $matters->firstWhere('client_id', $clients->first()->id);
                foreach (DocumentTemplate::take(2)->get() as $template) {
                    $document = $merger->generate($template, $portalMatter, $portalMatter->responsibleLawyer ?? $ceo, ['amount' => '150,000.00', 'deadline_days' => '15']);
                    $document->update(['shared_with_client' => true]);
                }

                NotarialEntry::create([
                    'notary_id' => $partner->id, 'doc_number' => 1, 'page_number' => 1, 'book_number' => 1,
                    'series_year' => $today->year, 'act_type' => 'acknowledgment',
                    'document_title' => 'Deed of Absolute Sale', 'principal_name' => $this->person(),
                    'competent_evidence' => 'Philippine Passport P1234567A', 'fee_cents' => 50000,
                    'notarized_at' => $today->subDays(12)->setTime(10, 30),
                ]);

                $conflicts->check($firm->id, $matters->first()->parties()->first()->name, $associates->first());
                $conflicts->check($firm->id, 'Juan Dela Cruz', $paralegal);

                if ($period = McleCompliancePeriod::current()) {
                    foreach ($lawyers as $i => $lawyer) {
                        foreach (array_slice([['Legal Ethics', 6], ['Trial and Pre-Trial Skills', 6], ['Alternative Dispute Resolution', 4], ['Updates on Substantive Law', 9], ['International Law', 6]], 0, 2 + $i % 4) as [$subject, $units]) {
                            McleCredit::create([
                                'user_id' => $lawyer->id, 'period_id' => $period->id,
                                'title' => "{$subject} Seminar", 'provider' => 'IBP Makati Chapter', 'subject_area' => $subject,
                                'units' => $units, 'date_earned' => $today->subDays(20 + $i * 17),
                            ]);
                        }
                    }
                }

                $this->advanced($firm, $matters, $clients, $portalMatter, $paralegal, $today);
            }, $ceo);

            $this->command?->info('Demo firm seeded. Sign in as ceo@demofirm.ph / password.');
        });
    }

    /** Fee arrangements, expenses, the task board, client messaging and online intake. */
    private function advanced(Firm $firm, $matters, $clients, Matter $portalMatter, User $paralegal, CarbonImmutable $today): void
    {
        $firm->update(['slug' => 'demo-law-partners', 'intake_enabled' => true, 'intake_message' => 'We reply to consultation requests within one business day.']);

        $arrangements = [
            ['fee_arrangement' => 'hourly', 'acceptance_fee_cents' => 5_000_000, 'appearance_fee_cents' => 750_000],
            ['fee_arrangement' => 'retainer', 'fixed_fee_cents' => 2_500_000],
            ['fee_arrangement' => 'flat', 'fixed_fee_cents' => 15_000_000, 'acceptance_fee_cents' => 3_000_000],
            ['fee_arrangement' => 'contingency', 'contingency_basis_points' => 2500, 'acceptance_fee_cents' => 2_000_000],
        ];
        foreach ($matters->take(4)->values() as $i => $matter) {
            $matter->update($arrangements[$i]);
        }

        $costs = [['filing_fee', 'Docket and legal research fees, complaint', 1_245_000], ['sheriff_fee', 'Sheriff’s fee for service of summons', 300_000], ['transcript', 'TSN of the pre-trial conference', 180_000], ['courier', 'LBC: pleadings to opposing counsel', 25_000]];
        foreach ($matters->take(5)->values() as $i => $matter) {
            foreach (array_slice($costs, 0, 2 + $i % 3) as $j => [$category, $description, $amount]) {
                Expense::create([
                    'matter_id' => $matter->id, 'user_id' => $paralegal->id, 'expense_date' => $today->subDays(5 + $i * 3 + $j),
                    'category' => $category, 'description' => $description, 'amount_cents' => $amount,
                ]);
            }
        }

        // Spread open tasks across the board.
        MatterDeadline::where('kind', 'task')->where('status', 'pending')->orderBy('id')->get()->each(function (MatterDeadline $task, int $i) {
            $task->forceFill(['progress' => ['todo', 'in_progress', 'review', 'todo'][$i % 4], 'priority' => ['normal', 'high', 'normal', 'urgent', 'low'][$i % 5]])->save();
        });

        $messaging = app(Messaging::class);
        $client = $clients->first();
        $thread = $messaging->start($portalMatter, $client, 'Pre-trial next week', 'Good day, Attorney. Do I need to attend the pre-trial in person, and should I bring the original contract?');
        $messaging->post($thread, $portalMatter->responsibleLawyer ?? $paralegal, 'Yes, please attend in person; the court requires the parties at pre-trial. Bring the original contract and two photocopies. We will meet at the courthouse lobby at 8:00 AM.');
        $messaging->markRead($thread, 'staff');

        $intake = app(IntakeService::class);
        $intake->submit($firm, [
            'name' => 'Ramon Villanueva', 'email' => 'ramon.villanueva@example.com', 'phone' => '0918 555 0101', 'client_type' => 'individual', 'case_type' => 'Labor',
            'description' => 'I was dismissed after 6 years without a notice or hearing, and my last two months of pay were withheld.',
            'opposing_parties' => ['Metro Cargo Movers Inc.'], 'preferred_times' => [$today->addDays(2)->setTime(10, 0)->toIso8601String()],
        ], null);
        $intake->submit($firm, [
            'name' => 'Teresa Aquino', 'email' => 'teresa.aquino@example.com', 'phone' => null, 'client_type' => 'corporate', 'case_type' => 'Civil',
            'description' => 'Our supplier failed to deliver goods we paid for in full and ignores our demand letters.',
            // Deliberately an existing client, so the automatic conflict check flags it.
            'opposing_parties' => [$clients->get(1)->name], 'preferred_times' => [],
        ], null);
    }

    private function user(Firm $firm, string $name, string $email, Role $role, int $rateCents, ?string $mobile = null): User
    {
        return User::create([
            'firm_id' => $firm->id,
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'hourly_rate_cents' => $rateCents,
            'mobile_number' => $mobile,
            'roll_number' => $role->isLawyer() ? '5'.$this->digits(4) : null,
            'ibp_number' => $role->isLawyer() ? $this->digits(6) : null,
        ]);
    }

    private function templates(): void
    {
        DocumentTemplate::create([
            'name' => 'Retainer Agreement',
            'category' => 'contract',
            'body' => <<<'TXT'
                RETAINER AGREEMENT

                This Retainer Agreement is entered into on {{ date_today }} by and between:

                {{ client_name }}, with address at {{ client_address }} ("CLIENT"); and

                {{ firm_name }}, with office address at {{ firm_address }}, represented by {{ lawyer_name }} ("COUNSEL").

                1. SCOPE. COUNSEL shall represent CLIENT in {{ matter_title }} ({{ matter_reference }}).

                2. FEES. CLIENT shall pay an acceptance fee of PHP {{ amount }}, exclusive of VAT, and appearance fees per hearing attended.

                3. TRUST FUNDS. Deposits for filing fees and costs shall be held in trust and accounted for in writing.

                CONFORME:

                ______________________            ______________________
                {{ client_name }}                  {{ lawyer_name }}
                                                  Roll No. {{ lawyer_roll_number }}
                                                  IBP No. {{ lawyer_ibp_number }}
                TXT,
        ]);

        DocumentTemplate::create([
            'name' => 'Demand Letter',
            'category' => 'letter',
            'body' => <<<'TXT'
                {{ date_today }}

                Dear Sir/Madam:

                We write on behalf of our client, {{ client_name }}, in connection with {{ matter_title }}.

                Formal demand is hereby made upon you to pay the amount of PHP {{ amount }} within {{ deadline_days }} days from receipt of this letter. Otherwise, we shall be constrained to pursue the appropriate legal remedies without further notice.

                Very truly yours,

                {{ lawyer_name }}
                {{ firm_name }}
                TXT,
        ]);

        DocumentTemplate::create([
            'name' => 'Verification and Certification Against Forum Shopping',
            'category' => 'pleading',
            'body' => <<<'TXT'
                VERIFICATION AND CERTIFICATION AGAINST FORUM SHOPPING

                I, {{ client_name }}, of legal age, after having been duly sworn in accordance with law, depose and state:

                1. I am the petitioner in {{ matter_title }}, docketed as {{ case_number }} before the {{ court }};
                2. I have caused the preparation of the foregoing pleading and have read its contents, which are true and correct of my personal knowledge and based on authentic records;
                3. I have not commenced any other action involving the same issues in any court, tribunal or quasi-judicial agency.

                ______________________
                {{ client_name }}
                Affiant
                TXT,
        ]);
    }

    private function deadlines($matters, DeadlineScheduler $scheduler, User $by, User $paralegal, CarbonImmutable $today): void
    {
        $rules = DeadlineRule::whereNull('firm_id')->get()->keyBy('name');

        foreach ($matters->filter(fn (Matter $m) => $m->status->isActive())->values() as $i => $matter) {
            $rule = $rules->values()[$i % $rules->count()];
            $scheduler->scheduleFromRule($matter, $rule, $today->subDays($rule->period_days - 2 - ($i % 9)), $by);

            $scheduler->scheduleManual($matter, $by, [
                'kind' => DeadlineKind::Hearing,
                'title' => $i % 2 ? 'Pre-trial conference' : 'Hearing on the merits',
                'due_date' => $today->addWeekdays(3 + $i * 2 % 25),
                'due_time' => $i % 2 ? '08:30' : '13:30',
                'location' => $matter->court,
            ]);

            if ($i % 4 === 0) {
                $scheduler->scheduleManual($matter, $by, [
                    'kind' => DeadlineKind::Task,
                    'title' => 'Prepare judicial affidavits',
                    'due_date' => $today->subDays(5),
                    'assigned_to' => $paralegal->id,
                ]);
            }
        }

        // A diligent firm: past items are done, except one left overdue so
        // the missed-deadline alerting has something to show.
        $past = MatterDeadline::pending()->whereDate('due_date', '<', $today)->orderBy('id')->get();
        foreach ($past->slice(1) as $deadline) {
            $scheduler->complete($deadline, $deadline->assigned_to === $paralegal->id ? $paralegal : $by, 'Complied.');
        }
    }

    private function time($matters, $people, CarbonImmutable $today): void
    {
        $tasks = ['Drafted answer to complaint', 'Conference with client', 'Legal research on prescription', 'Attended hearing', 'Reviewed pre-trial brief', 'Drafted motion for reconsideration', 'Prepared judicial affidavit'];

        foreach ($matters->take(14) as $i => $matter) {
            foreach (range(1, 4 + $i % 4) as $n) {
                $person = $people[($i + $n) % $people->count()];
                TimeEntry::create([
                    'matter_id' => $matter->id,
                    'user_id' => $person->id,
                    'work_date' => $today->subDays(($i * 3 + $n * 5) % 60),
                    'minutes' => [30, 45, 60, 90, 120, 180][($i + $n) % 6],
                    'rate_cents' => $person->hourly_rate_cents,
                    'description' => $tasks[($i + $n) % count($tasks)],
                ]);
            }
        }
    }

    private function money($matters, $clients, TrustLedgerService $ledger, InvoiceGenerator $invoices, User $by, CarbonImmutable $today): void
    {
        foreach ($clients->take(6) as $i => $client) {
            $matter = $matters->firstWhere('client_id', $client->id);
            $account = TrustAccount::create(['client_id' => $client->id, 'matter_id' => $matter?->id]);
            $ledger->deposit($account, 5000000 + $i * 2500000, 'Acceptance fee and filing costs deposit', 'OR-'.(1001 + $i), $by);
            $ledger->disburse($account, 350000 + $i * 50000, 'Docket and filing fees', 'OCC-'.(88100 + $i), $by);
        }

        // Invoice the first few matters: one paid from trust, one paid, the rest issued.
        foreach ($matters->take(5) as $i => $matter) {
            if (! TimeEntry::where('matter_id', $matter->id)->unbilled()->exists()) {
                continue;
            }

            $invoice = $invoices->issue($invoices->generateForMatter($matter, $by, null, $i === 4 ? 0 : 30));

            if ($i === 0) {
                $trust = TrustAccount::where('client_id', $matter->client_id)->first();
                if ($trust && $trust->balance_cents >= $invoice->total_cents) {
                    $invoices->markPaid($invoice, $by, null, $trust);
                }
            } elseif ($i === 1) {
                $invoices->markPaid($invoice, $by, 'BDO-TRX-55821');
            }
        }
    }
}
