<?php

namespace App\Services\Session;

use App\Enums\SessionStatus;
use App\Enums\TableStatus;
use App\Models\AppSession;
use App\Models\Payment;
use App\Models\Table;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Abre ou retoma a sessão de um cliente numa mesa.
 *
 * Usado pela entrada anônima do QR (POST /sessions, cliente identificado só
 * pelo telefone) e pelo vínculo de quem tem conta (POST /client/tables/link).
 * As duas portas seguem a mesma regra de ocupação.
 */
class TableSessionService
{
    /** Sessão em andamento na mesa (ativa ou fechando a conta), se houver. */
    public function occupant(Table $table): ?AppSession
    {
        return AppSession::where('table_id', $table->id)
            ->whereIn('status', [SessionStatus::ACTIVE->value, SessionStatus::CLOSING->value])
            ->first();
    }

    /**
     * @throws TableUnavailableException se a mesa já está em uso.
     */
    public function ensureFree(Table $table): void
    {
        if ($active = $this->occupant($table)) {
            throw $this->unavailable($active);
        }
    }

    /**
     * Abre a sessão do cliente na mesa, ou retoma a dele se já estiver em andamento.
     *
     * @return array{created: bool, session: AppSession, token: string, payment: ?Payment}
     *
     * @throws TableUnavailableException se a mesa está com outro cliente ou com o garçom.
     */
    public function open(Table $table, User $client): array
    {
        $active = $this->occupant($table);

        if ($active) {
            if ($active->user_id !== $client->id) {
                throw $this->unavailable($active);
            }

            return [
                'created' => false,
                'session' => $active,
                'token'   => $client->createToken('client')->plainTextToken,
                'payment' => $active->isClosing()
                    ? Payment::where('session_id', $active->id)->latest()->first()
                    : null,
            ];
        }

        $session = AppSession::create([
            'restaurant_id' => $table->restaurant_id,
            'table_id'      => $table->id,
            'user_id'       => $client->id,
            'session_token' => Str::uuid()->toString(),
            'device_id'     => preg_replace('/[^0-9]/', '', $client->phone ?? ''),
            'status'        => SessionStatus::ACTIVE,
        ]);

        $table->update(['status' => TableStatus::OCUPADA]);

        $client->tokens()->where('name', 'client')->delete();

        return [
            'created' => true,
            'session' => $session,
            'token'   => $client->createToken('client')->plainTextToken,
            'payment' => null,
        ];
    }

    /**
     * Corpo de resposta comum às duas portas — o app cliente trata igual.
     *
     * @param  array{created: bool, session: AppSession, token: string, payment: ?Payment}  $opened
     */
    public function payload(Table $table, User $client, array $opened): array
    {
        $restaurant = $table->restaurant;
        $payment    = $opened['payment'];

        return [
            'message'    => $opened['created'] ? 'Sessão criada.' : 'Sessão retomada.',
            'session'    => $opened['session'],
            'token'      => $opened['token'],
            'table'      => $table,
            'user'       => $client,
            'restaurant' => [
                'id'       => $restaurant->id,
                'slug'     => $restaurant->slug,
                'name'     => $restaurant->name,
                'logo_url' => $restaurant->logo_url,
            ],
            'payment' => $payment ? ['id' => $payment->id, 'amount' => $payment->amount] : null,
        ];
    }

    private function unavailable(AppSession $active): TableUnavailableException
    {
        // Sessão guest (aberta pelo garçom) não tem user_id: o garçom gerencia
        // pelo próprio app.
        return new TableUnavailableException($active->user_id === null
            ? 'Mesa ocupada. Solicite assistência ao garçom.'
            : 'Mesa ocupada por outro cliente.');
    }
}
