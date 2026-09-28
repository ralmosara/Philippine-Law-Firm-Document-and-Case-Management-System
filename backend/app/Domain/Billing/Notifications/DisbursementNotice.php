<?php

namespace App\Domain\Billing\Notifications;

use App\Domain\Billing\Models\DisbursementRequest;
use App\Notifications\Concerns\ShowsInApp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Something happened to a cash advance: requested, decided, released, or liquidation due. */
class DisbursementNotice extends Notification implements ShouldQueue
{
    use Queueable, ShowsInApp;

    public function __construct(public readonly DisbursementRequest $request, public readonly string $event) {}

    public function via(object $notifiable): array
    {
        // Liquidation that is overdue also goes by email; the rest is in-app.
        return $this->withInApp($notifiable, $this->event === 'liquidation_overdue' ? ['mail'] : []);
    }

    private function amount(): string
    {
        return '₱'.number_format($this->request->amount_cents / 100, 2);
    }

    private function title(): string
    {
        $r = $this->request->loadMissing(['matter:id,reference', 'requester:id,name']);
        $what = "{$this->amount()} for {$r->description} ({$r->matter?->reference})";

        return match ($this->event) {
            'requested' => "{$r->requester?->name} asks for {$what}",
            'approved' => "Approved: {$what}",
            'rejected' => "Not approved: {$what}".($r->decision_note ? ". {$r->decision_note}" : ''),
            'released' => "Released: {$what}. Liquidate by {$r->liquidation_due_on?->format('M j')}",
            'liquidation_due' => "Liquidate today: {$what}",
            default => "Liquidation overdue: {$what}",
        };
    }

    protected function inApp(object $notifiable): array
    {
        return [
            'kind' => $this->event === 'liquidation_overdue' ? 'deadline_missed' : 'disbursement',
            'title' => $this->title(),
            'body' => 'Cash advances',
            'url' => "/disbursements?open={$this->request->id}",
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Cash advance not yet liquidated')
            ->line($this->title().'.')
            ->line('Liquidate it with the receipts, and return any unspent cash.')
            ->action('Open cash advances', rtrim(config('app.frontend_url'), '/')."/disbursements?open={$this->request->id}");
    }
}
