<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    private function configureRateLimiters(): void
    {
        // Login: 10 tentativas por minuto por IP + identificador,
        // evita brute-force tanto distribuído quanto focado em uma conta.
        RateLimiter::for('login', function (Request $request) {
            $identifier = strtolower(trim(
                (string) ($request->input('phone')
                    ?? $request->input('email')
                    ?? $request->input('apelido')
                    ?? '')
            ));

            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perMinute(5)->by($identifier ?: $request->ip()),
            ];
        });

        // API autenticada: limite generoso por usuário (não por IP — vários
        // dispositivos do mesmo restaurante saem pelo mesmo IP via NAT).
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(300)->by('user:' . $request->user()->id)
                : Limit::perMinute(60)->by($request->ip());
        });

        // Webhook Mercado Pago: chega do IP do MP; mantemos teto alto
        // só para travar abuso óbvio se alguém descobrir o endpoint.
        RateLimiter::for('webhook', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });

        // Print agent: 1 daemon por restaurante fazendo polling; teto por token.
        RateLimiter::for('print-agent', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(180)->by('agent:' . $request->user()->id)
                : Limit::perMinute(30)->by($request->ip());
        });
    }
}
