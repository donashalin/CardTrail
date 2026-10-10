<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** Every CardTrail notification: always an email, plus a push to any device the person turned notifications on for. */
abstract class CardTrailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    abstract protected function subject(): string;

    /** @return list<string> the first line is also the push notification's text */
    abstract protected function lines(): array;

    abstract protected function url(): string;

    protected function action(): string
    {
        return 'Open CardTrail';
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $notifiable->pushSubscriptions()->exists() ? ['mail', WebPushChannel::class] : ['mail'];
    }

    /** Queued notifications go out a little later: skip anyone deactivated in the meantime. */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! $notifiable instanceof User || (bool) $notifiable->fresh()?->active;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Hello '.Str::before($notifiable->name, ' ').',');

        foreach ($this->lines() as $line) {
            $mail->line($line);
        }

        return $mail->action($this->action(), $this->url());
    }

    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->subject())
            ->body($this->lines()[0] ?? '')
            ->icon('/icons/icon-192.png')
            ->data(['url' => $this->url()])
            ->options(['TTL' => 86400]);
    }
}
