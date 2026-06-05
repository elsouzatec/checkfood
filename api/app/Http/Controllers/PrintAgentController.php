<?php

namespace App\Http\Controllers;

use App\Models\PrintJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints consumidos pelo agente local (script Node rodando na LAN do
 * restaurante). Autenticação via Bearer token Sanctum com habilidade
 * 'print-agent'.
 */
class PrintAgentController extends Controller
{
    /**
     * GET /api/print-agent/jobs/pending
     * Retorna jobs pendentes do restaurante do dono do token.
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $jobs = PrintJob::where('restaurant_id', $user->restaurant_id)
            ->where('status', 'pending')
            ->orderBy('id')
            ->limit(20)
            ->get(['id', 'payload', 'attempts']);

        return response()->json(['jobs' => $jobs]);
    }

    /**
     * PATCH /api/print-agent/jobs/{job}/printed
     */
    public function markPrinted(PrintJob $job, Request $request): JsonResponse
    {
        if ($job->restaurant_id !== $request->user()->restaurant_id) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $job->update([
            'status'     => 'printed',
            'printed_at' => now(),
            'attempts'   => $job->attempts + 1,
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * PATCH /api/print-agent/jobs/{job}/failed
     * Body: { error: string }
     */
    public function markFailed(PrintJob $job, Request $request): JsonResponse
    {
        if ($job->restaurant_id !== $request->user()->restaurant_id) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $request->validate(['error' => 'required|string|max:500']);

        $attempts = $job->attempts + 1;

        // Após 5 tentativas, marca como failed definitivamente.
        // Antes disso, mantém pending para nova tentativa.
        $status = $attempts >= 5 ? 'failed' : 'pending';

        $job->update([
            'status'     => $status,
            'attempts'   => $attempts,
            'last_error' => $request->error,
        ]);

        return response()->json(['ok' => true, 'status' => $status, 'attempts' => $attempts]);
    }
}
