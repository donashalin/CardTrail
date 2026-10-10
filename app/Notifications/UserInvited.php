<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvited extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  string|null  $token  set-password token, or null for Microsoft 365 users */
    public function __construct(public ?string $token) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('You have been added to CardTrail')
            ->greeting("Hello {$notifiable->name},")
            ->line('You now have access to CardTrail, where you complete your monthly card reconciliation.');

        if ($this->token === null) {
            return $mail
                ->line('Sign in with your Microsoft 365 work account.')
                ->action('Sign in to CardTrail', route('login'));
        }

        return $mail
            ->line('Choose a password to get started. This link expires in 7 days.')
            ->action('Set your password', route('invite.show', ['token' => $this->token, 'email' => $notifiable->email]));
    }
}
