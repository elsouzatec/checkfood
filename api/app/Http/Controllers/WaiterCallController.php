<?php

namespace App\Http\Controllers;

use App\Enums\SessionStatus;
use App\Models\AppSession;
use App\Models\WaiterCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WaiterCallController extends Controller
{
    /**
     * POST /api/waiter-calls
     * Cliente chama o garçom vinculado à sua mesa.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        // Buscar sessão ativa do cliente
        $session = AppSession::atTable()->where('user_id', $user->id)
            ->where('status', SessionStatus::ACTIVE)
            ->latest()
            ->first();

        if (!$session) {
            return response()->json(['message' => 'Nenhuma sessão ativa.'], 422);
        }

        // Verificar se tem garçom vinculado
        if (!$session->waiter_id) {
            return response()->json([
                'message' => 'Nenhum garçom vinculado à sua mesa. Aguarde a confirmação do seu primeiro pedido.',
            ], 422);
        }

        // Verificar se já tem chamado pendente (não expirado)
        $pendingCall = WaiterCall::where('session_id', $session->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if ($pendingCall) {
            // Retornar tempo restante
            $secondsLeft = now()->diffInSeconds($pendingCall->expires_at, false);
            return response()->json([
                'message' => 'Já existe um chamado pendente.',
                'call' => $pendingCall,
                'seconds_left' => max(0, $secondsLeft),
            ], 429);
        }

        // Expirar chamados antigos que ficaram pendentes
        WaiterCall::where('session_id', $session->id)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        // Criar chamado com timeout de 2 minutos
        $call = WaiterCall::create([
            'restaurant_id' => $session->restaurant_id,
            'session_id' => $session->id,
            'table_id' => $session->table_id,
            'client_user_id' => $user->id,
            'waiter_id' => $session->waiter_id,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(2),
        ]);

        return response()->json([
            'message' => 'Garçom chamado! Aguarde.',
            'call' => $call,
            'seconds_left' => 120,
        ], 201);
    }

    /**
     * GET /api/waiter-calls/status
     * Cliente verifica se tem chamado ativo.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $session = AppSession::atTable()->where('user_id', $user->id)
            ->where('status', SessionStatus::ACTIVE)
            ->latest()
            ->first();

        if (!$session) {
            return response()->json(['has_active_call' => false, 'can_call' => false]);
        }

        // Expirar pendentes vencidos
        WaiterCall::where('session_id', $session->id)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $activeCall = WaiterCall::where('session_id', $session->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        $hasWaiter = $session->waiter_id !== null;

        return response()->json([
            'has_active_call' => $activeCall !== null,
            'can_call' => $hasWaiter && $activeCall === null,
            'has_waiter' => $hasWaiter,
            'seconds_left' => $activeCall ? max(0, now()->diffInSeconds($activeCall->expires_at, false)) : 0,
        ]);
    }

    /**
     * GET /api/waiter-calls/pending
     * Garçom busca chamados pendentes direcionados a ele.
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        // Expirar pendentes vencidos deste garçom
        WaiterCall::where('waiter_id', $user->id)
            ->where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $calls = WaiterCall::where('waiter_id', $user->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->whereHas('table', fn($t) => $t->where('status', 'ocupada'))
            ->with(['table:id,number', 'client:id,name,phone'])
            ->orderBy('created_at')
            ->get();

        return response()->json(['calls' => $calls]);
    }

    /**
     * PATCH /api/waiter-calls/{waiterCall}/dismiss
     * Garçom marca o chamado como atendido.
     */
    public function dismiss(WaiterCall $waiterCall, Request $request): JsonResponse
    {
        $user = $request->user();

        if ($waiterCall->restaurant_id !== $user->restaurant_id) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        if ($waiterCall->waiter_id !== $user->id) {
            return response()->json(['message' => 'Este chamado não é seu.'], 403);
        }

        if ($waiterCall->status !== 'pending') {
            return response()->json(['message' => 'Chamado já resolvido.'], 422);
        }

        $waiterCall->update([
            'status' => 'dismissed',
            'dismissed_at' => now(),
        ]);

        return response()->json(['message' => 'Chamado atendido.']);
    }
}
