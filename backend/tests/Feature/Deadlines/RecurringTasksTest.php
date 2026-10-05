<?php

namespace Tests\Feature\Deadlines;

use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringTasksTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Manila'));
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Associate, $this->firm);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create(['responsible_lawyer_id' => $this->lawyer->id]);
    }

    private function task(array $input = []): int
    {
        return $this->postJson("/api/v1/matters/{$this->matter->id}/deadlines", ['kind' => 'task', 'title' => 'Monthly status report to client', 'due_date' => '2026-10-31', 'priority' => 'high', 'notes' => 'Use the report template', 'repeat' => 'monthly', ...$input])
            ->assertCreated()->json('id');
    }

    public function test_finishing_a_repeating_task_creates_the_next_once(): void
    {
        $id = $this->task();

        $this->postJson("/api/v1/deadlines/{$id}/complete")->assertOk();
        $next = MatterDeadline::where('title', 'Monthly status report to client')->where('id', '!=', $id)->sole();
        $this->assertSame('2026-11-30', $next->due_date->toDateString(), 'The 31st becomes the last day of a shorter month.');
        $this->assertSame(['high', 'Use the report template', 'monthly', $this->lawyer->id], [$next->priority->value, $next->notes, $next->repeat, $next->assigned_to]);
        $this->assertSame($next->id, MatterDeadline::find($id)->next_task_id);

        // Reopened and finished again on the board: no second copy.
        $this->postJson("/api/v1/tasks/{$id}/move", ['column' => 'todo'])->assertOk();
        $this->postJson("/api/v1/tasks/{$id}/move", ['column' => 'done'])->assertOk();
        $this->assertSame(2, MatterDeadline::where('title', 'Monthly status report to client')->count());
    }

    public function test_a_late_finish_skips_to_the_next_date_still_ahead(): void
    {
        $id = $this->task(['due_date' => '2026-06-15', 'repeat' => 'weekly']);
        MatterDeadline::whereKey($id)->update(['status' => 'missed']);

        $this->postJson("/api/v1/tasks/{$id}/move", ['column' => 'done'])->assertOk();
        $this->assertSame('2026-10-12', MatterDeadline::find(MatterDeadline::find($id)->next_task_id)->due_date->toDateString());
    }

    public function test_it_stops_after_the_end_date_or_when_the_matter_closes(): void
    {
        $id = $this->task(['due_date' => '2026-10-31', 'repeat' => 'quarterly', 'repeat_until' => '2026-12-31']);
        $this->postJson("/api/v1/deadlines/{$id}/complete")->assertOk();
        $this->assertNull(MatterDeadline::find($id)->next_task_id);

        $yearly = $this->task(['repeat' => 'yearly']);
        $this->matter->forceFill(['status' => 'closed'])->save();
        $this->postJson("/api/v1/deadlines/{$yearly}/complete")->assertOk();
        $this->assertNull(MatterDeadline::find($yearly)->next_task_id);
    }

    public function test_only_tasks_repeat(): void
    {
        $this->postJson("/api/v1/matters/{$this->matter->id}/deadlines", ['kind' => 'hearing', 'title' => 'Pre-trial', 'due_date' => '2026-11-03', 'repeat' => 'monthly'])
            ->assertJsonValidationErrors('repeat');
        $id = $this->task(['repeat' => null]);
        $this->patchJson("/api/v1/deadlines/{$id}", ['repeat' => 'weekly'])->assertOk()->assertJsonPath('repeat', 'weekly');
    }
}
