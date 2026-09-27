<?php

namespace App\Domain\Assistant\Jobs;

use App\Domain\Assistant\Assistant;
use App\Domain\Assistant\Models\AiMessage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Answers one question in the background, so a long answer never holds a
 * web request open. Not retried automatically: every call is billed, and
 * the lawyer can retry a failed answer from the conversation.
 */
class AnswerQuestion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public readonly int $messageId) {}

    public function handle(Assistant $assistant, TenantContext $tenant): void
    {
        $answer = AiMessage::withoutGlobalScopes()->find($this->messageId);

        if ($answer === null || $answer->status !== AiMessage::PENDING) {
            return;
        }

        // Run as the conversation's firm so every query stays tenant-scoped.
        $tenant->runAs((int) $answer->firm_id, fn () => $assistant->answer($answer));
    }

    public function failed(): void
    {
        AiMessage::withoutGlobalScopes()->whereKey($this->messageId)->where('status', AiMessage::PENDING)
            ->update(['status' => AiMessage::FAILED, 'error' => 'The assistant timed out. Please try again.']);
    }
}
