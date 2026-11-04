<?php

namespace App\Providers;

use App\Models\User\User;
use App\Services\Auth\JwtService;
use App\Services\Auth\ModulePermissions;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
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
