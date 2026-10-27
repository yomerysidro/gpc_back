<?php

namespace App\Providers;

use App\Models\User\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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
