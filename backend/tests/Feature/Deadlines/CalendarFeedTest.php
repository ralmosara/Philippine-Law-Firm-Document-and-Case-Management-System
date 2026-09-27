<?php

namespace Tests\Feature\Deadlines;

use App\Domain\Deadlines\Models\MatterDeadline;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Firm;
use App\Domain\Matters\Models\Matter;
use App\Enums\Role;
use App\Models\User;
use App\Support\Calendar\ICalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarFeedTest extends TestCase
{
    use RefreshDatabase;

    private Firm $firm;

    private User $lawyer;

    private Matter $matter;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://app.lex.ph']);
        $this->firm = Firm::factory()->create();
        $this->lawyer = $this->signIn(Role::Associate, $this->firm);
        $this->matter = Matter::factory()->for(Client::factory()->for($this->firm)->state(['name' => 'Juan Dela Cruz']))
            ->create(['title' => 'Dela Cruz v. Reyes', 'responsible_lawyer_id' => $this->lawyer->id, 'court' => 'RTC Makati']);
    }

    public function test_a_private_feed_lists_my_hearings_and_deadlines_without_client_details(): void
    {
        MatterDeadline::factory()->for($this->matter)->create(['kind' => 'hearing', 'title' => 'Pre-trial', 'due_date' => today()->addDays(5), 'due_time' => '08:30']);
        MatterDeadline::factory()->for($this->matter)->create(['kind' => 'filing', 'title' => 'Answer', 'due_date' => today()->addDays(9)]);
        $otherMatter = Matter::factory()->for(Client::factory()->for($this->firm))->create();
        MatterDeadline::factory()->for($otherMatter)->create(['kind' => 'filing', 'title' => 'Someone else’s', 'due_date' => today()->addDays(3)]);

        $url = $this->postJson('/api/v1/calendar-feed', ['scope' => 'mine', 'show_details' => false])->assertCreated()->json('url');
        $this->assertMatchesRegularExpression('#/api/calendar/[A-Za-z0-9]{40}\.ics$#', $url);

        $ics = $this->get(parse_url($url, PHP_URL_PATH))->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        $this->assertSame(2, substr_count($ics, 'BEGIN:VEVENT'));
        $this->assertStringContainsString('DTSTART;TZID=Asia/Manila:'.today()->addDays(5)->format('Ymd').'T083000', $ics);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:'.today()->addDays(9)->format('Ymd'), $ics);
        $this->assertStringContainsString("SUMMARY:Hearing: {$this->matter->reference}", $ics);
        $this->assertStringNotContainsString('Dela Cruz', $ics);   // privacy mode
        $this->assertStringNotContainsString('Someone else', $ics);

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }

    public function test_details_mode_and_firm_scope(): void
    {
        MatterDeadline::factory()->for($this->matter)->create(['kind' => 'hearing', 'title' => 'Pre-trial; mediation, too', 'due_date' => today()->addDays(5), 'due_time' => '08:30']);

        $url = $this->postJson('/api/v1/calendar-feed', ['scope' => 'firm', 'show_details' => true])->json('url');
        $ics = $this->get(parse_url($url, PHP_URL_PATH))->getContent();

        $this->assertStringContainsString('SUMMARY:Hearing: Pre-trial\; mediation\, too (Dela Cruz v. Reyes)', str_replace("\r\n ", '', $ics));
        $this->assertStringContainsString('LOCATION:RTC Makati', $ics);
    }

    public function test_replacing_or_revoking_the_feed_kills_the_old_url_and_deactivated_users_lose_access(): void
    {
        $old = parse_url($this->postJson('/api/v1/calendar-feed', ['scope' => 'mine', 'show_details' => false])->json('url'), PHP_URL_PATH);
        $new = parse_url($this->postJson('/api/v1/calendar-feed', ['scope' => 'mine', 'show_details' => false])->json('url'), PHP_URL_PATH);

        $this->get($old)->assertNotFound();
        $this->get($new)->assertOk();
        $this->getJson('/api/v1/calendar-feed')->assertJsonPath('feed.scope', 'mine')->assertJsonMissingPath('url');

        $this->lawyer->update(['is_active' => false]);
        $this->get($new)->assertNotFound();

        $this->lawyer->update(['is_active' => true]);
        $this->deleteJson('/api/v1/calendar-feed')->assertNoContent();
        $this->get($new)->assertNotFound();
    }

    public function test_long_lines_fold_without_splitting_characters(): void
    {
        $ics = (new ICalendar('Test'))->event([
            'uid' => 'x', 'stamp' => now(), 'start' => now(), 'all_day' => true,
            'summary' => str_repeat('Ñiño ', 40),
        ])->render();

        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
            $this->assertTrue(mb_check_encoding($line, 'UTF-8'));
        }
        $this->assertStringContainsString(trim(str_repeat('Ñiño ', 40)), str_replace("\r\n ", '', $ics));
    }
}
