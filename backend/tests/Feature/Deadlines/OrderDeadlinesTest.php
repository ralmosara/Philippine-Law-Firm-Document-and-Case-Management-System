<?php

namespace Tests\Feature\Deadlines;

use App\Domain\Documents\Models\MatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderDeadlinesTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Matter $matter;

    private MatterFile $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Manila'));
        config(['services.anthropic.api_key' => 'sk-ant-test', 'services.anthropic.model' => 'claude-opus-5-5']);
        $this->firm = Firm::factory()->create(['ai_enabled' => true]);
        $this->lawyer = $this->signIn(Role::Partner, $this->firm);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['responsible_lawyer_id' => $this->lawyer->id]);
        $this->file = MatterFile::create(['firm_id' => $this->firm->id, 'matter_id' => $this->matter->id, 'uploaded_by' => $this->lawyer->id, 'original_name' => 'decision.pdf', 'path' => 'x/decision.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 100, 'sha256' => str_repeat('a', 64)]);
        $this->file->forceFill(['content_text' => "DECISION\n\nWHEREFORE, the complaint is DISMISSED. SO ORDERED. September 28, 2026.\n\nRECEIVED: October 2, 2026"])->save();
    }

    private function answer(array $data, string $stop = 'end_turn'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-opus-5-5', 'stop_reason' => $stop,
            'content' => [['type' => 'text', 'text' => json_encode($data)]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);
    }

    private function suggest()
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/files/{$this->file->id}/deadline-suggestions");
    }

    public function test_periods_are_counted_from_receipt_under_rule_22(): void
    {
        $this->answer([
            'document_type' => 'Decision', 'document_date' => '2026-09-28', 'date_received' => '2026-10-02', 'date_received_quote' => 'RECEIVED: October 2, 2026',
            'summary' => 'The complaint was dismissed.',
            'deadlines' => [
                ['title' => 'Motion for reconsideration', 'kind' => 'filing', 'period_days' => 15, 'fixed_date' => null, 'fixed_time' => null, 'basis' => 'Rule 37 Sec. 1', 'quote' => 'the complaint is DISMISSED'],
                ['title' => 'Promulgation', 'kind' => 'hearing', 'period_days' => null, 'fixed_date' => '2026-11-03', 'fixed_time' => '08:30', 'basis' => 'Order', 'quote' => 'set'],
            ],
        ]);

        $res = $this->suggest()->assertOk();
        // 15 days from October 2 is Saturday, October 17: moved to Monday, October 19.
        $this->assertSame('2026-10-19', $res->json('deadlines.0.due_date'));
        $this->assertSame(['2026-11-03', '08:30'], [$res->json('deadlines.1.due_date'), $res->json('deadlines.1.due_time')]);
        $this->assertSame('2026-10-02', $res->json('date_received'));

        Http::assertSent(function (Request $request) {
            $body = $request->data();

            return $body['output_config']['format']['type'] === 'json_schema'
                && str_contains($body['messages'][0]['content'], 'WHEREFORE, the complaint is DISMISSED');
        });
    }

    public function test_without_a_receipt_date_no_due_date_is_guessed(): void
    {
        $this->answer(['document_type' => 'Order', 'document_date' => '2026-09-28', 'date_received' => null, 'date_received_quote' => null, 'summary' => '', 'deadlines' => [
            ['title' => 'Comment', 'kind' => 'filing', 'period_days' => 10, 'fixed_date' => null, 'fixed_time' => null, 'basis' => 'Order', 'quote' => 'comment within ten days'],
        ]]);

        $this->suggest()->assertOk()->assertJsonPath('deadlines.0.due_date', null)->assertJsonPath('deadlines.0.period_days', 10);
    }

    public function test_refusals_unread_files_and_firms_without_the_assistant_are_reported(): void
    {
        $this->answer([], 'refusal');
        $this->suggest()->assertJsonValidationErrors('file');

        $this->file->forceFill(['content_text' => null])->save();
        $this->suggest()->assertJsonValidationErrors('file');

        $this->firm->update(['ai_enabled' => false]);
        $this->suggest()->assertJsonValidationErrors('file');
    }
}
