<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\PrintJob;
use App\Services\Print\PrintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AdminPrintController extends Controller
{
    public function __construct(private PrintService $printService) {}

    /**
     * GET /api/admin/print/jobs
     * Lista os 10 últimos jobs do restaurante.
     */
    public function jobs(Request $request): JsonResponse
    {
        $jobs = PrintJob::where('restaurant_id', $request->user()->restaurant_id)
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return response()->json(['jobs' => $jobs]);
    }

    /**
     * DELETE /api/admin/print/jobs/cleanup
     * Apaga jobs já impressos (status='printed'). Mantém pending e failed para
     * que o admin não perca visibilidade do que ainda precisa de atenção.
     */
    public function cleanup(Request $request): JsonResponse
    {
        $deleted = PrintJob::where('restaurant_id', $request->user()->restaurant_id)
            ->where('status', 'printed')
            ->delete();

        return response()->json([
            'message' => "$deleted job(s) impresso(s) removido(s) do histórico.",
            'deleted' => $deleted,
        ]);
    }

    /**
     * POST /api/admin/print/jobs/{job}/retry
     * Reenfileira um job (volta para pending).
     */
    public function retry(PrintJob $job, Request $request): JsonResponse
    {
        if ($job->restaurant_id !== $request->user()->restaurant_id) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $job->update([
            'status'     => 'pending',
            'attempts'   => 0,
            'last_error' => null,
            'printed_at' => null,
        ]);

        return response()->json([
            'message' => 'Job reenfileirado.',
            'job'     => $job,
        ]);
    }

    /**
     * POST /api/admin/print/test
     * Cria um job de teste para validar agente/impressora.
     */
    public function test(Request $request): JsonResponse
    {
        $job = PrintJob::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'order_id'      => null,
            'payload'       => [
                'restaurant_name' => $request->user()->restaurant->name ?? 'PagFood',
                'sector'          => 'TESTE',
                'order_id'        => 0,
                'table_number'    => '—',
                'waiter_name'     => $request->user()->name,
                'created_at'      => now()->format('d/m/Y H:i'),
                'items'           => [
                    ['quantity' => 1, 'name' => '*** CUPOM DE TESTE ***', 'notes' => 'Se você consegue ler isso, o agente está funcionando.'],
                ],
                'item_count'      => 1,
                'is_test'         => true,
            ],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Cupom de teste enfileirado. Aguarde alguns segundos.',
            'job'     => $job,
        ]);
    }

    /**
     * POST /api/admin/print/agent-token
     * Gera (ou regenera) o token Sanctum do agente local.
     */
    public function generateAgentToken(Request $request): JsonResponse
    {
        $user = $request->user();

        // Revoga tokens antigos com a mesma habilidade
        $user->tokens()->where('name', 'print-agent')->delete();

        $plain = $user->createToken('print-agent', ['print-agent'])->plainTextToken;

        return response()->json([
            'message' => 'Token gerado. Salve-o agora — não será mostrado novamente.',
            'token'   => $plain,
        ]);
    }
}
