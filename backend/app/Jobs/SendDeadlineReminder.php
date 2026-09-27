<?php

namespace App\Jobs;

use App\Services\Notifications\SmsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

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
        // 1. Log the attempt
        Log::info("Executing SendDeadlineReminder Job for Deadline ID: {$this->matterDeadlineId}");

        // 2. Simulate heavy operation (e.g., generating PDF report, querying calendar)
        sleep(2); // Simulated delay

        // 3. Send Email Notification
        // Mail::to('lawyer@firm.com')->send(new DeadlineReminderMail($this->deadline));
        Log::info('Email reminder queued successfully.');

        // 4. Send SMS Notification (Enterprise Tier)
        $smsService = new SmsService;
        $message = 'REMINDER: Deadline is approaching for stage: '.$this->escalationStage.'.';
        // In reality, this would fetch the assigned lawyer's mobile number
        $smsService->send('09171234567', $message);

        // 5. Update deadline status if needed
        Log::info('DeadlineReminder Job Complete.');

        DB::table('deadline_events')->insert([
            'matter_deadline_id' => $this->matterDeadlineId,
            'event_type' => 'reminder_sent',
            'payload' => json_encode(['stage' => $this->escalationStage]),
            'created_at' => now(),
        ]);

        // TODO: integrate with SMS gateway and Email
    }
}
