<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
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
        $this->configureSqlite();
    }

    /**
     * As buscas por telefone usam REGEXP_REPLACE (só dígitos), que o MariaDB
     * de produção tem e o SQLite (testes e ambiente local) não: registra um
     * equivalente quando a conexão é SQLite.
     */
    private function configureSqlite(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            if ($event->connection->getDriverName() !== 'sqlite') {
                return;
            }

            $event->connection->getPdo()->sqliteCreateFunction(
                'REGEXP_REPLACE',
                fn ($subject, $pattern, $replacement) => preg_replace('#'.$pattern.'#u', $replacement, (string) $subject),
                3
            );
        });
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

        // Cadastro de conta de cliente: público, então limite por IP.
        RateLimiter::for('client-register', function (Request $request) {
            return [
                Limit::perMinute(5)->by($request->ip()),
                Limit::perHour(30)->by($request->ip()),
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
