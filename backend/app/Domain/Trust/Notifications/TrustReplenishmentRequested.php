<?php

namespace App\Domain\Trust\Notifications;

use App\Domain\Matters\Models\Firm;
use App\Domain\Trust\Models\TrustAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Asks a client to top up a trust deposit that fell below the agreed minimum. */
class TrustReplenishmentRequested extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly TrustAccount $account, public readonly int $shortfallCents) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $firm = Firm::findOrFail($this->account->firm_id);
        $peso = fn (int $cents) => '₱'.number_format($cents / 100, 2);

        $message = (new MailMessage)
            ->subject("Request to replenish your deposit with {$firm->name}")
            ->greeting("Dear {$notifiable->name},")
            ->line("The funds you deposited with us for expenses and fees (account {$this->account->account_number}) now stand at {$peso($this->account->balance_cents)}, below the agreed minimum of {$peso((int) $this->account->minimum_balance_cents)}.")
            ->line("To keep your matter moving without delay, please deposit {$peso($this->shortfallCents)}. Reply to this e-mail or call us for our bank details.");

        if ($notifiable->portal_enabled) {
            $message->action('See the account in the client portal', rtrim(config('app.frontend_url'), '/').'/portal');
        }

        return $message->salutation("Sincerely,\n{$firm->name}");
    }
}
