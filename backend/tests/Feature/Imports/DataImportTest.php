<?php

namespace Tests\Feature\Imports;

use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Domain\Trust\Models\TrustAccount;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;
use ZipArchive;

class DataImportTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->partner = $this->signIn(Role::ManagingPartner, $this->firm, ['email' => 'maria@firm.ph', 'name' => 'Atty. Maria Santos']);
    }

    private function csv(array $rows): UploadedFile
    {
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row, escape: '');
        }
        rewind($handle);

        return UploadedFile::fake()->createWithContent('import.csv', "\xEF\xBB\xBF".stream_get_contents($handle));
    }

    private function preview(string $type, UploadedFile $file): TestResponse
    {
        return $this->post('/api/v1/imports', ['type' => $type, 'file' => $file], ['Accept' => 'application/json']);
    }

    public function test_clients_are_previewed_with_duplicates_and_errors_then_committed(): void
    {
        Client::factory()->for($this->firm)->create(['name' => 'Acme Trading Corporation', 'email' => 'legal@acme.ph']);

        $response = $this->preview('clients', $this->csv([
            ['Client Name', 'E-mail Address', 'Mobile', 'TIN', 'Favorite color'],
            ['Juan Dela Cruz', 'juan@example.com', '0917 555 0101', '123-456-789-000', 'blue'],
            ['ACME Trading Corp.', '', '', '', ''],                        // same as the existing client
            ['Bayanihan Holdings, Inc.', 'not-an-email', '', '', ''],     // error
            ['juan dela cruz', '', '', '', ''],                           // repeated in the file
            ['Luzon Logistics Corp.', 'ops@luzon.ph', '', '', ''],
        ]))->assertCreated();

        $response->assertJsonPath('status', 'previewed')
            ->assertJsonPath('summary.total', 5)
            ->assertJsonPath('summary.ready', 2)
            ->assertJsonPath('summary.duplicate', 2)
            ->assertJsonPath('summary.error', 1)
            ->assertJsonPath('summary.ignored_columns', ['Favorite color'])
            ->assertJsonPath('rows.0.line', 2)
            ->assertJsonPath('rows.1.messages.0', 'Already a client: Acme Trading Corporation.')
            ->assertJsonPath('rows.2.messages.0', '"not-an-email" is not a valid e-mail address.')
            ->assertJsonPath('rows.3.messages.0', 'The same client appears earlier in this file.');

        $this->assertSame(1, Client::count()); // nothing written by the preview

        $this->postJson('/api/v1/imports/'.$response->json('id').'/commit')
            ->assertOk()
            ->assertJsonPath('status', 'committed')
            ->assertJsonPath('summary.created', 2);

        $this->assertDatabaseHas('clients', ['name' => 'Juan Dela Cruz', 'type' => 'individual', 'phone' => '0917 555 0101', 'firm_id' => $this->firm->id]);
        $this->assertDatabaseHas('clients', ['name' => 'Luzon Logistics Corp.', 'type' => 'corporate']); // inferred from "Corp."
        $this->postJson('/api/v1/imports/'.$response->json('id').'/commit')->assertStatus(422); // only once
    }

    public function test_an_excel_workbook_is_read(): void
    {
        $this->preview('clients', $this->xlsx([
            ['Name', 'Email', 'Notes'],
            ['Maria Clara Ibarra', 'maria@example.com', 'Shared & inline'],
        ]))->assertCreated()
            ->assertJsonPath('summary.ready', 1)
            ->assertJsonPath('rows.0.raw.name', 'Maria Clara Ibarra')
            ->assertJsonPath('rows.0.raw.notes', 'Shared & inline');
    }

    public function test_missing_required_columns_and_bad_files_are_refused(): void
    {
        $this->preview('matters', $this->csv([['Title'], ['Something']]))
            ->assertStatus(422)->assertJsonValidationErrors(['file' => 'Missing column: Client']);
        $this->preview('clients', UploadedFile::fake()->createWithContent('clients.csv', ''))->assertStatus(422);
        $this->preview('clients', UploadedFile::fake()->create('clients.pdf', 10, 'application/pdf'))->assertStatus(422);
    }

    public function test_matters_keep_their_stage_legacy_reference_and_opposing_parties(): void
    {
        $client = Client::factory()->for($this->firm)->create(['name' => 'Luzon Logistics & Freight Corp.', 'email' => 'legal@luzon.ph']);

        $id = $this->preview('matters', $this->csv([
            ['Client', 'Case Title', 'Nature', 'Docket No.', 'Stage', 'Handling Lawyer', 'File No', 'Opposing Party', 'Date Opened'],
            ['legal@luzon.ph', 'Luzon Logistics v. Fernandez', 'civil', 'CV-2019-0441', 'pre-trial', 'maria@firm.ph', 'LIT-2019-044', 'Ramon Fernandez; Fernandez Trucking', '03/15/2019'],
            ['Luzon Logistics & Freight Corp', 'Collection vs Reyes', '', '', '', '', '', '', ''],
            ['Unknown Client', 'Some case', '', '', '', '', '', '', ''],
            ['legal@luzon.ph', 'Bad stage', '', '', 'settled', '', '', '', ''],
        ]))->assertCreated()
            ->assertJsonPath('summary.ready', 2)
            ->assertJsonPath('rows.1.warnings.0', 'No case type given; set to Civil.')
            ->assertJsonPath('rows.2.messages.0', 'No client named "Unknown Client". Import clients first, or check the spelling.')
            ->json('id');

        $this->postJson("/api/v1/imports/{$id}/commit")->assertOk();

        $matter = Matter::where('reference', 'LIT-2019-044')->firstOrFail();
        $this->assertSame('pre_trial', $matter->status->value);
        $this->assertSame('Civil', $matter->case_type);
        $this->assertSame('2019-03-15', $matter->opened_at->toDateString());
        $this->assertSame($this->partner->id, $matter->responsible_lawyer_id);
        $this->assertSame(['Fernandez Trucking', 'Ramon Fernandez'], $matter->parties()->orderBy('name')->pluck('name')->all());
        $this->assertDatabaseHas('matter_status_events', ['matter_id' => $matter->id, 'to_status' => 'pre_trial', 'reason' => 'Imported from spreadsheet']);
        $this->assertSame(0, $matter->deadlines()->count()); // no intake checklist for a case already under way

        $other = Matter::where('title', 'Collection vs Reyes')->firstOrFail();
        $this->assertSame('intake', $other->status->value);
        $this->assertMatchesRegularExpression('/^M-\d{4}-\d{4}$/', $other->reference);

        // Importing the same file again finds them all.
        $this->preview('matters', $this->csv([
            ['Client', 'Title', 'Case number'],
            ['legal@luzon.ph', 'A different title', 'cv 2019-0441'],
        ]))->assertJsonPath('rows.0.messages.0', 'Already a matter: LIT-2019-044 Luzon Logistics v. Fernandez.');
        $this->assertSame($client->id, $matter->client_id);
    }

    public function test_deadlines_attach_to_matters_by_reference_or_docket_and_refuse_past_dates(): void
    {
        $matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['case_number' => 'CV-2026-5220']);
        $due = today()->addDays(20);

        $id = $this->preview('deadlines', $this->csv([
            ['Docket No.', 'Particulars', 'Hearing Date', 'Time', 'Venue'],
            ['cv-2026-5220', 'Pre-trial hearing', $due->format('m/d/Y'), '8:30 AM', 'RTC Manila Br. 21'],
            [$matter->reference, 'File pre-trial brief', $due->copy()->subDays(5)->format('Y-m-d'), '', ''],
            [$matter->reference, 'Old deadline', today()->subDay()->format('m/d/Y'), '', ''],
            ['NO-SUCH', 'Orphan', $due->format('m/d/Y'), '', ''],
        ]))->assertCreated()
            ->assertJsonPath('summary.ready', 2)
            ->assertJsonPath('rows.2.messages.0', 'Due date '.today()->subDay()->toFormattedDateString().' has passed.')
            ->json('id');

        $this->postJson("/api/v1/imports/{$id}/commit")->assertOk();

        $hearing = MatterDeadline::where('title', 'Pre-trial hearing')->firstOrFail();
        $this->assertSame('hearing', $hearing->kind->value); // inferred from the title
        $this->assertSame('08:30', substr((string) $hearing->due_time, 0, 5));
        $this->assertSame($matter->responsible_lawyer_id, $hearing->assigned_to);
        $this->assertSame('filing', MatterDeadline::where('title', 'File pre-trial brief')->value('kind')->value);

        // Undo removes deadlines nobody has touched.
        $this->postJson("/api/v1/imports/{$id}/undo")->assertOk()->assertJsonPath('undone', 2);
        $this->assertSame(0, MatterDeadline::count());
    }

    public function test_trust_opening_balances_open_accounts_and_undo_reverses_them(): void
    {
        $client = Client::factory()->for($this->firm)->create(['name' => 'Juan Dela Cruz']);

        $id = $this->preview('trust_balances', $this->csv([
            ['Client', 'Opening Balance', 'As of', 'Reference'],
            ['Juan Dela Cruz', '₱150,000.50', '08/31/2026', 'Old ledger p. 14'],
            ['Juan Dela Cruz', '0', '', ''],
        ]))->assertCreated()->assertJsonPath('summary.ready', 1)->assertJsonPath('summary.error', 1)->json('id');

        $this->postJson("/api/v1/imports/{$id}/commit")->assertOk();

        $account = TrustAccount::where('client_id', $client->id)->firstOrFail();
        $this->assertSame(15_000_050, $account->balance_cents);
        $this->assertDatabaseHas('trust_transactions', ['trust_account_id' => $account->id, 'type' => 'deposit', 'amount_cents' => 15_000_050, 'description' => 'Opening balance brought forward (as of August 31, 2026)']);

        // A second import of the same client is a duplicate.
        $this->preview('trust_balances', $this->csv([['Client', 'Balance'], ['Juan Dela Cruz', '100']]))
            ->assertJsonPath('rows.0.status', 'duplicate');

        $this->postJson("/api/v1/imports/{$id}/undo")->assertOk()->assertJsonPath('undone', 1);
        $account->refresh();
        $this->assertSame(0, $account->balance_cents);
        $this->assertSame('closed', $account->status);
        $this->assertSame(2, $account->transactions()->count()); // the ledger keeps both entries
    }

    public function test_undo_keeps_records_that_have_been_used(): void
    {
        $id = $this->preview('clients', $this->csv([['Name'], ['Used Client'], ['Unused Client']]))->json('id');
        $this->postJson("/api/v1/imports/{$id}/commit")->assertOk();
        Matter::factory()->for(Client::where('name', 'Used Client')->firstOrFail())->create();

        $this->postJson("/api/v1/imports/{$id}/undo")
            ->assertOk()
            ->assertJsonPath('undone', 1)
            ->assertJsonPath('kept.0', 'Used Client now has matters, trust accounts or invoices.')
            ->assertJsonPath('status', 'undone');

        $this->assertDatabaseMissing('clients', ['name' => 'Unused Client']);
        $this->assertDatabaseHas('clients', ['name' => 'Used Client']);
        $this->postJson("/api/v1/imports/{$id}/undo")->assertStatus(422);
    }

    public function test_a_row_that_became_a_duplicate_stops_the_commit(): void
    {
        $id = $this->preview('clients', $this->csv([['Name'], ['Pedro Penduko'], ['Maria Makiling']]))->assertJsonPath('summary.ready', 2)->json('id');
        Client::factory()->for($this->firm)->create(['name' => 'Pedro Penduko']);

        $this->postJson("/api/v1/imports/{$id}/commit")->assertStatus(422);

        $this->getJson("/api/v1/imports/{$id}")
            ->assertJsonPath('status', 'previewed')
            ->assertJsonPath('rows.0.status', 'duplicate')
            ->assertJsonPath('summary.ready', 1);
        $this->assertDatabaseMissing('clients', ['name' => 'Maria Makiling']);

        $this->postJson("/api/v1/imports/{$id}/commit")->assertOk()->assertJsonPath('summary.created', 1);
    }

    public function test_permissions_templates_and_tenancy(): void
    {
        $this->get('/api/v1/imports/template/matters')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $types = $this->getJson('/api/v1/imports/types')->assertOk()->json();
        $this->assertSame(['clients', 'matters', 'deadlines', 'trust_balances'], array_column($types, 'type'));

        $id = $this->preview('clients', $this->csv([['Name'], ['Somebody']]))->json('id');

        $this->signIn(Role::Associate, $this->firm);
        $this->preview('clients', $this->csv([['Name'], ['X']]))->assertForbidden();
        $this->getJson('/api/v1/imports')->assertForbidden();

        $this->signIn(Role::ManagingPartner, Firm::factory()->create());
        $this->getJson("/api/v1/imports/{$id}")->assertNotFound();
        $this->postJson("/api/v1/imports/{$id}/commit")->assertNotFound();
    }

    /** A minimal real .xlsx: shared strings, an inline string and a number. */
    private function xlsx(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        $shared = [];
        $sheetRows = '';
        foreach ($rows as $r => $row) {
            $cells = '';
            foreach (array_values($row) as $c => $value) {
                $ref = chr(65 + $c).($r + 1);
                if ($c === 2) { // third column as an inline string
                    $cells .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value, ENT_XML1).'</t></is></c>';
                } else {
                    $shared[] = $value;
                    $cells .= '<c r="'.$ref.'" t="s"><v>'.(count($shared) - 1).'</v></c>';
                }
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$cells.'</row>';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Clients" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($s) => '<si><t>'.htmlspecialchars($s, ENT_XML1).'</t></si>', $shared)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();

        return new UploadedFile($path, 'clients.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
