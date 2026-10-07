<?php

namespace Tests\Feature\Intake;

use App\Domain\Intake\Models\IntakeRequest;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IntakeQuestionsTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->firm = Firm::factory()->create(['slug' => 'santos-reyes', 'intake_enabled' => true]);
        $this->signIn(Role::ManagingPartner, $this->firm);
    }

    private function submit(array $answers)
    {
        return $this->postJson('/api/public/intake/santos-reyes', [
            'name' => 'Pedro Santos', 'email' => 'pedro@example.ph', 'client_type' => 'individual', 'case_type' => 'Labor',
            'description' => 'I was dismissed from work without notice last month.', 'consent' => true, 'answers' => $answers,
        ]);
    }

    public function test_the_firms_questions_are_asked_for_that_type_of_case_and_carried_to_the_matter(): void
    {
        $this->putJson('/api/v1/intake-questions', ['questions' => [
            'Labor' => [
                ['label' => 'Date of dismissal', 'type' => 'date', 'required' => true],
                ['label' => 'Monthly salary (₱)', 'type' => 'number', 'required' => false, 'hint' => 'Basic pay'],
            ],
            'Land Registration' => [['label' => 'Title number', 'type' => 'text', 'required' => true]],
        ]])->assertOk()->assertJsonPath('questions.Labor.0.key', 'date_of_dismissal');
        $this->putJson('/api/v1/intake-questions', ['questions' => ['Astrology' => []]])->assertStatus(422);

        $this->getJson('/api/public/intake/santos-reyes')->assertOk()->assertJsonPath('questions.Labor.1.hint', 'Basic pay');

        $this->submit([])->assertJsonValidationErrors(['answers.date_of_dismissal' => 'Date of dismissal']);
        $this->submit(['date_of_dismissal' => '2026-09-15', 'monthly_salary' => '18000'])->assertCreated();

        $request = IntakeRequest::sole();
        $this->assertSame([['label' => 'Date of dismissal', 'answer' => '2026-09-15'], ['label' => 'Monthly salary (₱)', 'answer' => '18000']], $request->answers);
        $this->getJson("/api/v1/intake-requests/{$request->id}")->assertOk()->assertJsonPath('answers.0.answer', '2026-09-15');

        $matter = $this->postJson("/api/v1/intake-requests/{$request->id}/accept")->assertSuccessful()->json('matter_id') ?? IntakeRequest::sole()->matter_id;
        $this->assertStringContainsString("Answers on the consultation form:\n- Date of dismissal: 2026-09-15", Matter::findOrFail($matter)->description);
    }

    public function test_other_types_of_case_are_unaffected(): void
    {
        $this->putJson('/api/v1/intake-questions', ['questions' => ['Land Registration' => [['label' => 'Title number', 'type' => 'text', 'required' => true]]]])->assertOk();
        $this->submit([])->assertCreated();
        $this->assertSame([], IntakeRequest::sole()->answers);
    }
}
