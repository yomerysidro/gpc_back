<?php

namespace App\Providers;

use App\Models\User\User;
use App\Services\Auth\GmailApiTransport;
use App\Services\Auth\JwtService;
use App\Services\Auth\ModulePermissions;
use App\Services\RequestReadMemo;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('gmail-api', fn (array $config): GmailApiTransport => new GmailApiTransport($config));

        DB::listen(RequestReadMemo::invalidateAfterWrite(...));

        RateLimiter::for('public-certificates', function (Request $request): array {
            $dni = $request->input('dni');
            $key = is_string($dni) && preg_match('/^[0-9]{8}$/D', $dni) === 1 ? $dni : 'invalid';
            $key = hash_hmac('sha256', $key, (string) config('app.key'));
            $response = fn (Request $request, array $headers) => response()->json([
                'success' => false, 'message' => 'Demasiadas consultas. Intente más tarde.', 'data' => ['certificados' => []],
            ], 429, [...$headers, 'Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);

            return [Limit::perMinute(5)->by('ip-minute:'.$request->ip())->response($response),
                Limit::perHour(30)->by('ip-hour:'.$request->ip())->response($response),
                Limit::perHour(10)->by('dni:'.$key)->response($response)];
        });

        Auth::viaRequest('jwt-cookie', function (Request $request): ?User {
            $token = $request->cookie(JwtService::COOKIE);
            if (! is_string($token) || $token === '') {
                return null;
            }
            try {
                $payload = app(JwtService::class)->decode($token);
            } catch (Throwable) {
                return null;
            }
            if (! isset($payload->sub) || ! ctype_digit((string) $payload->sub) || (int) $payload->sub < 1) {
                return null;
            }
            $user = User::with(['person', 'roles'])->find($payload->sub);

            return $user && $user->estado && ! $user->bloqueado_hasta?->isFuture() ? $user : null;
        });

        foreach (['viewAny' => 'ver', 'view' => 'ver', 'create' => 'crear', 'update' => 'editar', 'delete' => 'eliminar'] as $ability => $action) {
            Gate::define($ability, fn (User $user, Model|string $target): bool => ModulePermissions::allows($user, $target, $action));
        }

        // Los controllers tipan {id} como int: un id no numérico debe dar 404, no un TypeError.
        Route::pattern('id', '[0-9]+');

        // El enlace del correo apunta al frontend Angular, no a una ruta de Laravel.
        ResetPassword::createUrlUsing(function (User $user, string $token) {
            return config('app.frontend_url').'/reset-password?'.http_build_query([
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ]);
        });
    }
}
