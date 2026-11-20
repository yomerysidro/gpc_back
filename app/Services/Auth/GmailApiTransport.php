<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

class GmailApiTransport extends AbstractTransport
{
    /** @param array{client_id?: string|null, client_secret?: string|null, refresh_token?: string|null} $credentials */
    public function __construct(#[SensitiveParameter] private array $credentials)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'gmail-api';
    }

    protected function doSend(SentMessage $message): void
    {
        try {
            $authorization = Http::asForm()->connectTimeout(4)->timeout(8)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'refresh_token',
                'client_id' => $this->credentials['client_id'] ?? '',
                'client_secret' => $this->credentials['client_secret'] ?? '',
                'refresh_token' => $this->credentials['refresh_token'] ?? '',
            ]);
            $token = $authorization->json('access_token');
            if (! $authorization->successful() || ! is_string($token) || $token === '') {
                throw new TransportException('Gmail rechazó la autorización de correo.');
            }

            $raw = rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '=');
            $delivery = Http::withToken($token)->acceptJson()->connectTimeout(4)->timeout(8)
                ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', ['raw' => $raw]);
            if (! $delivery->successful() || ! is_string($delivery->json('id'))) {
                throw new TransportException('Gmail no aceptó el envío de correo.');
            }
        } catch (ConnectionException) {
            throw new TransportException('No se pudo conectar con la API de Gmail.');
        }
    }
}
