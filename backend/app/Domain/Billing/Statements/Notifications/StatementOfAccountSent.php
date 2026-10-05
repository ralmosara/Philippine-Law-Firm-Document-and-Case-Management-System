<?php

namespace App\Domain\Billing\Statements\Notifications;

use App\Domain\Billing\Statements\StatementOfAccount;
use App\Domain\Matters\Models\Client;
use App\Support\Localization\PortalLocale;
use App\Support\Pdf\PdfRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A client's statement of account, as a PDF, with the email in the client's language. */
class StatementOfAccountSent extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Client $client, public readonly CarbonImmutable $asOf) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $statement = app(StatementOfAccount::class)->build($this->client->fresh(), $this->asOf);
        $money = fn (int $cents) => PdfRenderer::money($cents);
        $date = PortalLocale::date($this->asOf);

        $message = (new MailMessage)
            ->subject(__('Statement of account as of :date', ['date' => $date]))
            ->greeting(__('Dear :name,', ['name' => $notifiable->name]))
            ->line(__('Attached is your statement of account as of :date.', ['date' => $date]));

        $message->line($statement['total_due'] > 0
            ? __('Total amount due: :amount.', ['amount' => $money($statement['total_due'])])
            : __('Nothing is owed. Thank you.'));
        if ($statement['trust'] !== []) {
            $message->line(__('Funds we hold in trust for you: :amount.', ['amount' => $money($statement['trust_total'])]));
        }
        if ($notifiable->portal_enabled) {
            $message->action(__('View in the client portal'), rtrim(config('app.frontend_url'), '/').'/portal');
        }

        return $message
            ->line(__('Please contact us if anything does not match your records. If you have paid recently, thank you: your payment may not appear yet.'))
            ->salutation(__('Sincerely,')."\n{$statement['firm']->name}")
            ->attachData(app(PdfRenderer::class)->render('pdf.statement', $statement), 'statement-of-account-'.$this->asOf->toDateString().'.pdf', ['mime' => 'application/pdf']);
    }
}
