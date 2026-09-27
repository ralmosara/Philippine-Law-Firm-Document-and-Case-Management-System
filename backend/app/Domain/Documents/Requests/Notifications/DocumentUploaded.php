<?php

namespace App\Domain\Documents\Requests\Notifications;

use App\Domain\Documents\Requests\DocumentRequest;
use App\Domain\Documents\Requests\DocumentRequestItem;
use App\Domain\Matters\Models\Client;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** In the bell: a client uploaded a requested document, ready for review. */
class DocumentUploaded extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly DocumentRequestItem $item, public readonly DocumentRequest $request, public readonly Client $client) {}

    public function via(object $notifiable): array
    {
        return $this->withInApp($notifiable, []);
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => 'document_uploaded',
            'title' => "{$this->client->name} uploaded: {$this->item->label}",
            'body' => "For \"{$this->request->title}\". Ready to review.",
            'url' => "/matters/{$this->request->matter_id}?tab=files",
        ];
    }
}
