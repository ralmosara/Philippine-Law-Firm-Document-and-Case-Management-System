<?php

namespace Tests\Feature\Deadlines;

use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaskBoardTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private Matter $matter;

    private User $paralegal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->firm = Firm::factory()->create();
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        $this->paralegal = $this->signIn(Role::Paralegal, $this->firm);
    }

    public function test_tasks_move_across_the_board_and_every_move_is_logged(): void
    {
        $id = $this->postJson("/api/v1/matters/{$this->matter->id}/deadlines", [
            'kind' => 'task', 'title' => 'Prepare judicial affidavits', 'due_date' => today()->addWeek()->toDateString(),
            'assigned_to' => $this->paralegal->id, 'priority' => 'high',
        ])->assertCreated()->assertJsonPath('progress', 'todo')->assertJsonPath('priority', 'high')->json('id');

        $this->postJson("/api/v1/tasks/{$id}/move", ['column' => 'in_progress'])->assertOk()->assertJsonPath('progress', 'in_progress');
        $this->postJson("/api/v1/tasks/{$id}/move", ['column' => 'done'])->assertOk()->assertJsonPath('status', 'completed');
        $this->postJson("/api/v1/tasks/{$id}/move", ['column' => 'review'])->assertOk()
            ->assertJsonPath('status', 'pending')->assertJsonPath('progress', 'review');

        $this->assertSame(['moved', 'completed', 'reopened', 'moved'], MatterDeadline::find($id)->events()->reorder('id')->pluck('event_type')->slice(1)->values()->all());
    }

    public function test_the_board_lists_open_and_recently_finished_tasks_with_filters(): void
    {
        $mine = MatterDeadline::factory()->for($this->matter)->create(['kind' => 'task', 'title' => 'Mine', 'assigned_to' => $this->paralegal->id]);
        MatterDeadline::factory()->for($this->matter)->create(['kind' => 'task', 'title' => 'Unassigned']);
        MatterDeadline::factory()->for($this->matter)->create(['kind' => 'task', 'title' => 'Old done', 'status' => 'completed', 'completed_at' => now()->subDays(30)]);
        MatterDeadline::factory()->for($this->matter)->create(['kind' => 'filing', 'title' => 'Not a task']);

        $this->getJson('/api/v1/tasks')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/v1/tasks?assignee=me')->assertJsonCount(1)->assertJsonPath('0.id', $mine->id);
        $this->getJson('/api/v1/tasks?assignee=unassigned')->assertJsonCount(1)->assertJsonPath('0.title', 'Unassigned');
    }

    public function test_overdue_tasks_can_still_be_finished(): void
    {
        $task = MatterDeadline::factory()->for($this->matter)->create(['kind' => 'task', 'status' => 'missed', 'due_date' => today()->subDays(3)]);

        $this->postJson("/api/v1/tasks/{$task->id}/move", ['column' => 'in_progress'])->assertOk()->assertJsonPath('status', 'missed');
        $this->postJson("/api/v1/tasks/{$task->id}/move", ['column' => 'done'])->assertOk()->assertJsonPath('status', 'completed');
    }

    public function test_only_tasks_move_and_other_firms_cannot_touch_them(): void
    {
        $filing = MatterDeadline::factory()->for($this->matter)->create(['kind' => 'filing']);
        $this->postJson("/api/v1/tasks/{$filing->id}/move", ['column' => 'done'])->assertStatus(422);

        $task = MatterDeadline::factory()->for($this->matter)->create(['kind' => 'task']);
        $this->signIn(Role::Paralegal);
        $this->postJson("/api/v1/tasks/{$task->id}/move", ['column' => 'done'])->assertNotFound();

        $this->signIn(Role::Staff, $this->firm);
        $this->postJson("/api/v1/tasks/{$task->id}/move", ['column' => 'done'])->assertForbidden();
    }
}
