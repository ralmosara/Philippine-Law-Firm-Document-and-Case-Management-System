<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Models\Document;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

class PleadingTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create(['name' => 'Santos & Reyes Law', 'address' => '18/F Ayala Tower One, Ayala Avenue, Makati City']);
        $this->lawyer = $this->signIn(Role::Partner, $this->firm, [
            'name' => 'Atty. Maria Santos', 'email' => 'maria@santosreyes.ph',
            'roll_number' => '65432', 'ibp_number' => '123456, 01/05/2026, Makati', 'ptr_number' => '7654321, 01/06/2026, Makati City', 'mcle_compliance_number' => 'VIII-0012345, valid until 04/14/2028',
        ]);
        $client = Client::factory()->for($this->firm)->create(['name' => 'Luzon Logistics & Freight Corp.']);
        $this->matter = Matter::factory()->for($client)->create([
            'title' => 'Luzon Logistics v. Fernandez', 'case_type' => 'Civil', 'case_number' => 'CV-2026-5220',
            'court' => 'Regional Trial Court, Manila', 'court_branch' => 'Branch 21', 'responsible_lawyer_id' => $this->lawyer->id,
            'client_role' => 'plaintiff', 'nature_of_action' => 'Sum of Money and Damages',
        ]);
        $this->matter->parties()->create(['role' => 'adverse_party', 'name' => 'Ramon Fernandez', 'counsel_name' => 'Atty. Jose Rizal']);
    }

    private function preview(array $options): string
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/pleadings/preview", $options)->assertOk()->json('text');
    }

    public function test_a_complaint_has_the_caption_signature_verification_and_certification(): void
    {
        $text = $this->preview(['type' => 'complaint', 'title' => 'Complaint for Sum of Money']);

        foreach ([
            'REPUBLIC OF THE PHILIPPINES', 'REGIONAL TRIAL COURT', 'Branch 21, Manila',
            'LUZON LOGISTICS & FREIGHT CORP.,', 'Plaintiff,', '- versus -', 'RAMON FERNANDEZ,', 'Defendant.',
            'Civil Case No. CV-2026-5220', 'For: Sum of Money and Damages', 'x - - -',
            'COMPLAINT FOR SUM OF MONEY', 'Plaintiff LUZON LOGISTICS & FREIGHT CORP., by counsel',
            'PRAYER', 'Respectfully submitted.', 'Makati City, Philippines',
            'ATTY. MARIA SANTOS', 'Roll of Attorneys No. 65432', 'IBP No. 123456', 'PTR No. 7654321', 'MCLE Compliance No. VIII-0012345', 'maria@santosreyes.ph',
            'VERIFICATION', 'CERTIFICATION AGAINST FORUM SHOPPING', 'SUBSCRIBED AND SWORN', 'EXPLANATION', 'Copy furnished:', 'ATTY. JOSE RIZAL',
        ] as $expected) {
            $this->assertStringContainsString($expected, $text, "Missing: {$expected}");
        }

        // The plaintiff comes first, then the defendant.
        $this->assertLessThan(strpos($text, 'RAMON FERNANDEZ'), strpos($text, 'LUZON LOGISTICS'));
    }

    public function test_an_answer_puts_the_client_second_and_has_no_verification_by_default(): void
    {
        $this->matter->update(['client_role' => 'defendant']);
        $text = $this->preview(['type' => 'answer']);

        $this->assertLessThan(strpos($text, 'LUZON LOGISTICS'), strpos($text, 'RAMON FERNANDEZ'));
        $this->assertMatchesRegularExpression('/RAMON FERNANDEZ,\n\s+Plaintiff,/', $text);
        $this->assertMatchesRegularExpression('/LUZON LOGISTICS & FREIGHT CORP\.,\n\s+Defendant\./', $text);
        $this->assertStringContainsString('Counsel for the Defendant', $text);
        $this->assertStringNotContainsString('VERIFICATION', $text);
        $this->assertStringNotContainsString('FORUM SHOPPING', $text);

        $this->assertStringContainsString('VERIFICATION', $this->preview(['type' => 'answer', 'verification' => true]));
    }

    public function test_criminal_cases_are_captioned_people_of_the_philippines(): void
    {
        $this->matter->update(['case_type' => 'Criminal', 'client_role' => 'accused', 'case_number' => 'R-MNL-26-01234-CR', 'nature_of_action' => 'Estafa']);
        $text = $this->preview(['type' => 'motion', 'title' => 'Motion to Quash']);

        $this->assertMatchesRegularExpression('/PEOPLE OF THE PHILIPPINES,\n\s+Plaintiff,/', $text);
        $this->assertMatchesRegularExpression('/LUZON LOGISTICS & FREIGHT CORP\.,\n\s+Accused\./', $text);
        $this->assertStringContainsString('Criminal Case No. R-MNL-26-01234-CR', $text);
        $this->assertStringContainsString('MOTION TO QUASH', $text);
    }

    public function test_the_pleading_is_saved_as_a_document_and_exports_to_word(): void
    {
        $this->firm->update(['pleading_paper' => 'folio', 'pleading_font' => 'Book Antiqua', 'pleading_font_size' => 14]);
        $id = $this->postJson("/api/v1/matters/{$this->matter->id}/pleadings", ['type' => 'complaint', 'body' => "1. Plaintiff is a corporation.\n\n2. Defendant owes ₱2,450,000.00."])
            ->assertCreated()->assertJsonPath('title', 'Complaint — Luzon Logistics v. Fernandez')->json('id');

        $document = Document::findOrFail($id);
        $this->assertSame(1, $document->versions()->count());

        $response = $this->get("/api/v1/documents/{$id}/docx")->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        $this->assertStringContainsString('complaint-luzon-logistics-v-fernandez.docx', $response->headers->get('Content-Disposition'));

        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $xml = $zip->getFromName('word/document.xml');
        $styles = $zip->getFromName('word/styles.xml');
        $zip->close();
        unlink($path);

        $this->assertNotFalse(simplexml_load_string($xml), 'document.xml is well-formed');
        $this->assertStringContainsString('<w:pgSz w:w="12240" w:h="18720"/>', $xml);            // 8.5 x 13 in
        $this->assertStringContainsString('w:left="2160"', $xml);                                // 1.5 in left margin
        $this->assertStringContainsString('<w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t xml:space="preserve">REPUBLIC OF THE PHILIPPINES', $xml);
        $this->assertStringContainsString('<w:tbl>', $xml);                                       // the caption's two columns
        $this->assertStringContainsString('Civil Case No. CV-2026-5220', $xml);
        $this->assertStringContainsString('Defendant owes ₱2,450,000.00.', $xml);
        $this->assertStringContainsString('w:ascii="Book Antiqua"', $styles);
        $this->assertStringContainsString('<w:sz w:val="28"/>', $styles);                        // 14 pt
    }

    public function test_lawyers_keep_their_own_credentials_current(): void
    {
        $this->putJson('/api/v1/auth/credentials', ['ptr_number' => '8888888, 01/04/2027, Makati City', 'mcle_compliance_number' => 'IX-0001234'])
            ->assertOk()->assertJsonPath('user.ptr_number', '8888888, 01/04/2027, Makati City');

        $this->assertStringContainsString('PTR No. 8888888', $this->preview(['type' => 'motion']));
    }

    public function test_other_firms_cannot_assemble_or_export(): void
    {
        $id = $this->postJson("/api/v1/matters/{$this->matter->id}/pleadings", ['type' => 'motion'])->json('id');

        $this->signIn(Role::Partner, Firm::factory()->create());
        $this->postJson("/api/v1/matters/{$this->matter->id}/pleadings/preview", ['type' => 'motion'])->assertNotFound();
        $this->get("/api/v1/documents/{$id}/docx")->assertNotFound();
    }
}
