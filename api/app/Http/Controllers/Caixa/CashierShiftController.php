<?php

namespace App\Http\Controllers\Caixa;

use App\Enums\CashierShiftStatus;
use App\Http\Controllers\Controller;
use App\Models\CashierShift;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Turno de caixa por operador.
 *
 * - Cada cashier pode ter no máximo um turno OPEN por vez.
 * - O turno persiste entre logins/F5 até ser fechado pela tela de fechamento.
 * - Pagamentos registrados em CaixaController::confirmPayment são vinculados
 *   ao turno aberto do operador.
 */
class CashierShiftController extends Controller
{
    /**
     * GET /api/caixa/shift
     *
     * Retorna o turno aberto do operador atual (ou null), com totais por
     * método de pagamento já recebidos no turno.
     */
    public function current(Request $request): JsonResponse
    {
        $user = $request->user();

        $shift = CashierShift::where('cashier_id', $user->id)
            ->where('status', CashierShiftStatus::OPEN)
            ->latest('opened_at')
            ->first();

        if (!$shift) {
            return response()->json(['shift' => null]);
        }

        return response()->json(['shift' => $this->shiftPayload($shift)]);
    }

    /**
     * POST /api/caixa/shift/open
     *
     * Body: { opening_amount: float }
     */
    public function open(Request $request): JsonResponse
    {
        $request->validate([
            'opening_amount' => 'required|numeric|min:0',
        ]);

        $user = $request->user();

        $existing = CashierShift::where('cashier_id', $user->id)
            ->where('status', CashierShiftStatus::OPEN)
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'Você já tem um turno aberto.',
                'shift'   => $this->shiftPayload($existing),
            ], 422);
        }

        $shift = CashierShift::create([
            'restaurant_id'  => $user->restaurant_id,
            'cashier_id'     => $user->id,
            'opening_amount' => (float) $request->input('opening_amount'),
            'status'         => CashierShiftStatus::OPEN,
            'opened_at'      => now(),
        ]);

        return response()->json([
            'message' => 'Caixa aberto.',
            'shift'   => $this->shiftPayload($shift),
        ], 201);
    }

    /**
     * POST /api/caixa/shift/close
     *
     * Body: { closing_amount: float, notes?: string }
     */
    public function close(Request $request): JsonResponse
    {
        $request->validate([
            'closing_amount' => 'required|numeric|min:0',
            'notes'          => 'nullable|string|max:500',
        ]);

        $user = $request->user();

        $shift = CashierShift::where('cashier_id', $user->id)
            ->where('status', CashierShiftStatus::OPEN)
            ->first();

        if (!$shift) {
            return response()->json(['message' => 'Nenhum turno aberto para fechar.'], 422);
        }

        $totals = $this->computeTotals($shift);

        $expected   = round($shift->opening_amount + ($totals['by_method']['dinheiro']['total'] ?? 0), 2);
        $closing    = round((float) $request->input('closing_amount'), 2);
        $difference = round($closing - $expected, 2);

        $shift->update([
            'closing_amount'  => $closing,
            'expected_amount' => $expected,
            'difference'      => $difference,
            'notes'           => $request->input('notes'),
            'status'          => CashierShiftStatus::CLOSED,
            'closed_at'       => now(),
        ]);

        return response()->json([
            'message' => 'Caixa fechado.',
            'shift'   => $this->shiftPayload($shift->fresh()),
        ]);
    }

    /**
     * Calcula totais por método de pagamento dos pagamentos do turno.
     */
    private function computeTotals(CashierShift $shift): array
    {
        $rows = Payment::where('cashier_shift_id', $shift->id)
            ->where('status', 'paid')
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as qty')
            ->groupBy('method')
            ->get();

        $byMethod   = [];
        $grandTotal = 0.0;
        foreach ($rows as $r) {
            $byMethod[$r->method] = [
                'total' => round((float) $r->total, 2),
                'qty'   => (int) $r->qty,
            ];
            $grandTotal += (float) $r->total;
        }

        return [
            'by_method'   => $byMethod,
            'grand_total' => round($grandTotal, 2),
        ];
    }

    private function shiftPayload(CashierShift $shift): array
    {
        $totals = $this->computeTotals($shift);

        $expectedCash = round($shift->opening_amount + ($totals['by_method']['dinheiro']['total'] ?? 0), 2);

        return [
            'id'              => $shift->id,
            'status'          => $shift->status->value,
            'opening_amount'  => round((float) $shift->opening_amount, 2),
            'closing_amount'  => $shift->closing_amount !== null ? round((float) $shift->closing_amount, 2) : null,
            'expected_amount' => $shift->expected_amount !== null ? round((float) $shift->expected_amount, 2) : $expectedCash,
            'difference'      => $shift->difference !== null ? round((float) $shift->difference, 2) : null,
            'notes'           => $shift->notes,
            'opened_at'       => $shift->opened_at?->toIso8601String(),
            'closed_at'       => $shift->closed_at?->toIso8601String(),
            'cashier' => [
                'id'      => $shift->cashier_id,
                'name'    => $shift->cashier?->name,
                'apelido' => $shift->cashier?->apelido,
            ],
            'totals' => $totals,
        ];
    }
}
