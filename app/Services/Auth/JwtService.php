<?php

namespace App\Services\Auth;

use App\Models\User\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Symfony\Component\HttpFoundation\Cookie;

class JwtService
{
    public const COOKIE = 'access_token';

    private const ALGORITHM = 'HS256';

    /**
     * Firma un JWT con sub (id_usuario), rol (nombre) y exp.
     */
    public function createToken(User $user): string
    {
        $now = time();

        return JWT::encode([
            'sub' => $user->id_usuario,
            'rol' => $user->roleName(),
            'iat' => $now,
            'exp' => $now + $this->ttlMinutes() * 60,
        ], $this->secret(), self::ALGORITHM);
    }

    /**
     * Valida firma y expiración. Lanza una excepción si el token no es válido.
     */
    public function decode(string $token): object
    {
        return JWT::decode($token, new Key($this->secret(), self::ALGORITHM));
    }

    public function makeCookie(string $token): Cookie
    {
        return $this->cookie($token, $this->ttlMinutes());
    }

    public function expireCookie(): Cookie
    {
        return $this->cookie('', -2628000);
    }

    /**
     * HttpOnly, SameSite=Strict, Path=/. Secure en todo entorno salvo local.
     */
    private function cookie(string $value, int $minutes): Cookie
    {
        return cookie(
            self::COOKIE,
            $value,
            $minutes,
            '/',
            null,
            ! app()->environment('local'),
            true,
            false,
            Cookie::SAMESITE_STRICT
        );
    }

    private function secret(): string
    {
        return (string) config('jwt.secret');
    }

    private function ttlMinutes(): int
    {
        return (int) config('jwt.ttl');
    }
}
