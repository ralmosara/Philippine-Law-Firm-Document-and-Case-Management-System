<?php

namespace App\Domain\Documents\Requests\Notifications;

use App\Domain\Documents\Requests\DocumentRequest;
use App\Domain\Matters\Models\Firm;
use App\Support\Localization\PortalLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells a client which documents the firm needs, with a link to upload them. */
class DocumentsRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly DocumentRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->request->fresh('items');
        $firm = Firm::findOrFail($request->firm_id);
        $message = (new MailMessage)
            ->subject(__('Documents needed: :title', ['title' => $request->title]))
            ->greeting(__('Dear :name,', ['name' => $notifiable->name]))
            ->line(__(':firm needs the following documents for your matter:', ['firm' => $firm->name]));

        foreach ($request->items as $item) {
            $message->line('• '.$item->label.($item->required ? '' : ' '.__('(if available)')));
        }
        if ($request->message) {
            $message->line('"'.$request->message.'"');
        }
        if ($request->due_on) {
            $message->line(__('Please upload them by :date.', ['date' => PortalLocale::date($request->due_on)]));
        }

        return $message
            ->action(__('Upload in the client portal'), rtrim(config('app.frontend_url'), '/')."/portal/requests/{$request->id}")
            ->salutation(__('Sincerely,')."\n{$firm->name}");
    }
}
