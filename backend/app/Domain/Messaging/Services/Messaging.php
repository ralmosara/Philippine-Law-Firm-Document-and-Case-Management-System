<?php

namespace App\Domain\Messaging\Services;

use App\Domain\Documents\Actions\StoreMatterFile;
use App\Domain\Matters\Models\Client;
use App\Domain\Matters\Models\Matter;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\MessageThread;
use App\Domain\Messaging\Notifications\NewMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Secure client-lawyer messaging. Attachments become matter files (virus
 * scanned, searchable, visible to the client). The other side is emailed a
 * content-free alert, once per batch of unread messages.
 */
class Messaging
{
    public function __construct(private readonly StoreMatterFile $files) {}

    public function start(Matter $matter, User|Client $sender, string $subject, string $body, ?UploadedFile $attachment = null): MessageThread
    {
        $thread = MessageThread::create([
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
            'client_id' => $matter->client_id,
            'subject' => $subject,
        ]);

        $this->post($thread, $sender, $body, $attachment);

        return $thread;
    }

    public function post(MessageThread $thread, User|Client $sender, string $body, ?UploadedFile $attachment = null): Message
    {
        $thread->loadMissing('matter');
        $file = $attachment ? $this->files->execute($thread->matter, $attachment, $sender, "Attached to message: {$thread->subject}", sharedWithClient: true) : null;
        $side = $sender instanceof Client ? 'client' : 'staff';

        [$message, $firstUnread] = DB::transaction(function () use ($thread, $sender, $body, $file, $side) {
            $thread = MessageThread::whereKey($thread->id)->lockForUpdate()->firstOrFail();

            // Only the first unread message in a run triggers an email alert.
            $recipientRead = $side === 'client' ? $thread->staff_last_read_id : $thread->client_last_read_id;
            $firstUnread = ! Message::where('thread_id', $thread->id)
                ->where('sender_type', $sender->getMorphClass())
                ->where('id', '>', $recipientRead)
                ->exists();

            $message = Message::create([
                'firm_id' => $thread->firm_id,
                'thread_id' => $thread->id,
                'sender_type' => $sender->getMorphClass(),
                'sender_id' => $sender->getKey(),
                'body' => $body,
                'matter_file_id' => $file?->id,
            ]);

            // Writing a message means the sender has read the thread.
            $thread->forceFill([
                'last_message_at' => $message->created_at,
                $side === 'client' ? 'client_last_read_id' : 'staff_last_read_id' => $message->id,
            ])->save();

            return [$message, $firstUnread];
        });

        if ($firstUnread) {
            $this->alertRecipients($thread->fresh(['matter.responsibleLawyer', 'client']), $sender);
        }

        return $message;
    }

    public function markRead(MessageThread $thread, string $side): void
    {
        $latest = (int) Message::where('thread_id', $thread->id)->max('id');
        $thread->forceFill([$side === 'client' ? 'client_last_read_id' : 'staff_last_read_id' => $latest])->save();
    }

    private function alertRecipients(MessageThread $thread, User|Client $sender): void
    {
        if ($sender instanceof User) {
            if ($thread->client?->email) {
                $thread->client->notify(new NewMessage($thread, forClient: true));
            }

            return;
        }

        // The responsible lawyer, plus any staff who have written in the thread.
        $staffIds = Message::where('thread_id', $thread->id)->where('sender_type', 'user')->distinct()->pluck('sender_id')
            ->push($thread->matter?->responsible_lawyer_id)
            ->filter()
            ->unique();

        Notification::send(User::whereIn('id', $staffIds)->where('is_active', true)->get(), new NewMessage($thread, forClient: false));
    }
}
