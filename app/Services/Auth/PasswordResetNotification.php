<?php

namespace App\Services\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class PasswordResetNotification extends ResetPassword
{
    /** @param mixed $notifiable */
    public function toMail($notifiable): MailMessage
    {
        $mailer = (string) config('mail.password_reset');
        $message = parent::toMail($notifiable)->mailer($mailer);

        if ($mailer === 'gmail-api') {
            $message->from((string) config('mail.mailers.gmail-api.sender'), (string) config('mail.from.name'));
        }

        return $message;
    }
}
