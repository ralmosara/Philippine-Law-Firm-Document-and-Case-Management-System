<?php

namespace Tests\Feature\Compliance;

use App\Domain\Compliance\Enums\ConflictCheckStatus;
use App\Domain\Compliance\Services\ConflictChecker;
use App\Domain\Compliance\Services\NameMatcher;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Smalot\PdfParser\Parser;
use Tests\TestCase;

class SmarterConflictCheckTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create(['name' => 'Santos & Reyes Law']);
        $this->lawyer = $this->signIn(Role::Partner, $this->firm, ['name' => 'Atty. Maria Santos']);
    }

    /** @return array<string, array{0: string, 1: string, 2: ?int}> */
    public static function names(): array
    {
        return [
            'particles apart or together' => ['Juan De La Cruz', 'Juan Dela Cruz', 100],
            'surname first with a comma' => ['juan dela cruz', 'DELA CRUZ, Juan', 100],
            'Ma. for Maria' => ['Ma. Clara Santos', 'Maria Clara Santos', 100],
            'company forms ignored' => ['Acme Trading Corp.', 'ACME TRADING CORPORATION', 100],
            'titles and generations ignored' => ['Atty. Jose Rizal Jr.', 'Jose Rizal', 100],
            'accents ignored' => ['Santiago Peña', 'Santiago Pena', 100],
            'one word within a longer name' => ['Penduko', 'Pedro Penduko', 90],
            'misspelled surname' => ['Ramon Fernandes', 'Ramon Fernandez', 75],
            'middle initial and Sr. set aside' => ['Jose P. Laurel', 'Jose Laurel Sr.', 100],
            'same surname and first initial' => ['DELA CRUZ, Juan Miguel', 'Juan Carlos Dela Cruz', 60],
            'only the surname in common' => ['Jose Santos', 'Maria Santos', null],
            'different people' => ['Pedro Penduko', 'Juan Tamad', null],
        ];
    }

    #[DataProvider('names')]
    public function test_names_match_the_way_philippine_names_are_written(string $query, string $candidate, ?int $score): void
    {
        $this->assertSame($score, (new NameMatcher)->compare($query, $candidate)['score'] ?? null);
    }

    public function test_misspellings_and_other_names_are_caught_and_ranked(): void
    {
        $client = Client::factory()->for($this->firm)->create(['name' => 'Maria Clara Reyes', 'aliases' => "Maria Clara Santos\nClara's Bakeshop"]);
        $matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['title' => 'Collection case']);
        $matter->parties()->create(['role' => 'adverse_party', 'name' => 'Ramon Fernandez']);

        // A maiden name finds the client.
        $check = app(ConflictChecker::class)->check($this->firm->id, 'Ma. Clara Santos', $this->lawyer);
        $this->assertSame($client->id, $check->matches[0]['id']);
        $this->assertSame('Same name (also known as Maria Clara Santos)', $check->matches[0]['reason']);

        // A misspelled opposing party is still flagged as adverse.
        $check = app(ConflictChecker::class)->check($this->firm->id, 'Ramon Fernandes', $this->lawyer);
        $this->assertSame(ConflictCheckStatus::Flagged, $check->status);
        $this->assertTrue($check->matches[0]['is_adverse']);
        $this->assertSame(75, $check->matches[0]['score']);
        $this->assertSame('Similar spelling', $check->matches[0]['reason']);
    }

    public function test_the_report_is_a_pdf_for_the_file(): void
    {
        Client::factory()->for($this->firm)->create(['name' => 'Juan Dela Cruz']);
        $id = $this->postJson('/api/v1/conflict-checks', ['name' => 'Juan de la Cruz'])->assertCreated()->json('id');
        $this->postJson("/api/v1/conflict-checks/{$id}/resolve", ['status' => 'waived', 'notes' => 'Same person; he is our client. Written consent on file.'])->assertOk();

        $response = $this->get("/api/v1/conflict-checks/{$id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $text = (new Parser)->parseContent($response->getContent())->getText();

        foreach (['Conflict-of-interest check', 'Juan de la Cruz', 'Atty. Maria Santos', 'Same name', 'Waived', 'Written consent on file'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }

        $this->signIn(Role::Partner, Firm::factory()->create());
        $this->get("/api/v1/conflict-checks/{$id}/pdf")->assertNotFound();
    }

    public function test_anonymizing_a_client_also_forgets_their_other_names(): void
    {
        $client = Client::factory()->for($this->firm)->create(['name' => 'Maria Clara Reyes', 'aliases' => 'Maria Clara Santos']);
        $this->signIn(Role::ManagingPartner, $this->firm);
        $this->postJson("/api/v1/clients/{$client->id}/anonymize", ['confirm' => true])->assertOk();

        $this->assertNull($client->fresh()->aliases);
    }
}
