<?php

namespace App\Domain\Documents\Requests\Notifications;

use App\Domain\Documents\Requests\DocumentRequest;
use App\Domain\Matters\Models\Firm;
use App\Support\Localization\PortalLocale;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A reminder of documents still missing, before or after the due date. */
class DocumentsReminder extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  list<string>  $missing */
    public function __construct(public readonly DocumentRequest $request, public readonly array $missing, public readonly string $stage) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firm = Firm::findOrFail($this->request->firm_id);
        $due = PortalLocale::date($this->request->due_on);
        $message = (new MailMessage)
            ->subject($this->stage === 'overdue' ? __('Still needed: documents for :title', ['title' => $this->request->title]) : __('Reminder: documents for :title', ['title' => $this->request->title]))
            ->greeting(__('Dear :name,', ['name' => $notifiable->name]))
            ->line($this->stage === 'overdue'
                ? __('We have not yet received these documents, which we needed by :due:', ['due' => $due])
                : __('A reminder that we need these documents by :due:', ['due' => $due]));

        foreach ($this->missing as $label) {
            $message->line("• {$label}");
        }

        return $message
            ->action(__('Upload in the client portal'), rtrim(config('app.frontend_url'), '/')."/portal/requests/{$this->request->id}")
            ->line(__('If something is hard to get, reply to this e-mail and we will help.'))
            ->salutation(__('Sincerely,')."\n{$firm->name}");
    }
}
