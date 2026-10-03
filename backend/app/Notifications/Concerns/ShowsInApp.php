<?php

namespace App\Notifications\Concerns;

use App\Domain\Push\WebPushChannel;
use App\Models\User;
use Illuminate\Notifications\Messages\BroadcastMessage;

/**
 * Adds a notification to the staff notification center: stored for the bell
 * (database) and pushed live to the user's private channel (broadcast).
 * Clients and plain e-mail addresses only get the e-mail.
 *
 * The notification describes itself with inApp(): a kind (for the icon), a
 * one-line title, an optional body, and the page it links to.
 */
trait ShowsInApp
{
    /** @return array{kind: string, title: string, body?: string|null, url: string} */
    abstract protected function inApp(object $notifiable): array;

    /**
     * Staff also get it on their phones and browsers, where they turned
     * notifications on (Web Push).
     *
     * @param  list<string|class-string>  $channels
     */
    protected function withInApp(object $notifiable, array $channels): array
    {
        if (! $notifiable instanceof User) {
            return $channels;
        }

        return [...$channels, 'database', 'broadcast', ...(WebPushChannel::wanted($notifiable) ? [WebPushChannel::class] : [])];
    }

    public function toArray(object $notifiable): array
    {
        return $this->inApp($notifiable);
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->inApp($notifiable));
    }

    /** The in-app copy is kept for the bell, but only the latest ~100 are shown. */
    public function databaseType(object $notifiable): string
    {
        return $this->inApp($notifiable)['kind'];
    }
}
