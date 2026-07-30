<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendDeadlineReminder implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public $matterDeadlineId,
        public string $escalationStage // '72hr', '24hr', 'day-of'
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // 1. Fetch MatterDeadline with relationships (Matter -> Counsel)
        // 2. Check if it's already 'met' or 'missed' to avoid sending late reminders
        // 3. Send email/SMS notification to Counsel
        // 4. Log to `deadline_events` (append-only)
        
        \Illuminate\Support\Facades\DB::table('deadline_events')->insert([
            'matter_deadline_id' => $this->matterDeadlineId,
            'event_type' => 'reminder_sent',
            'payload' => json_encode(['stage' => $this->escalationStage]),
            'created_at' => now(),
        ]);
        
        // TODO: integrate with SMS gateway and Email
    }
}
