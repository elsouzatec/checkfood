<?php

namespace App\Http\Controllers\Platform;

use App\Enums\SubscriptionStatus;
use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gestão de mensalidades pelo super admin: configurar valor/vencimento,
 * registrar pagamentos e consultar o histórico. A suspensão automática por
 * inadimplência é feita pelo comando billing:check-overdue.
 */
class PlatformBillingController extends Controller
{
    public function show(Restaurant $restaurant): JsonResponse
    {
        $subscription = $restaurant->subscription;

        $payments = SubscriptionPayment::where('restaurant_id', $restaurant->id)
            ->latest('paid_at')
            ->limit(50)
            ->get();

        return response()->json([
            'subscription' => $subscription ? $this->subscriptionData($subscription) : null,
            'payments'     => $payments,
        ]);
    }

    /**
     * Cria/atualiza a configuração da mensalidade (valor e dia de vencimento).
     */
    public function update(Request $request, Restaurant $restaurant): JsonResponse
    {
        $data = $request->validate([
            'monthly_amount' => 'required|numeric|min:0',
            'billing_day'    => 'nullable|integer|min:1|max:28',
            'notes'          => 'nullable|string|max:255',
        ]);

        $subscription = Subscription::firstOrNew(['restaurant_id' => $restaurant->id]);
        $subscription->monthly_amount = $data['monthly_amount'];
        $subscription->billing_day    = $data['billing_day'] ?? $subscription->billing_day;
        $subscription->notes          = $data['notes'] ?? $subscription->notes;

        if (!$subscription->exists) {
            $subscription->status = SubscriptionStatus::TRIAL;
        }

        $subscription->save();

        return response()->json([
            'message'      => 'Mensalidade configurada.',
            'subscription' => $this->subscriptionData($subscription),
        ]);
    }

    /**
     * Registra um pagamento manual: estende paid_until em 1 mês, marca a
     * assinatura em dia e reativa o restaurante caso estivesse suspenso.
     */
    public function pay(Request $request, Restaurant $restaurant): JsonResponse
    {
        $data = $request->validate([
            'amount'  => 'nullable|numeric|min:0',
            'paid_at' => 'nullable|date',
            'method'  => 'nullable|string|max:30',
            'note'    => 'nullable|string|max:255',
        ]);

        $result = DB::transaction(function () use ($data, $restaurant, $request) {
            $subscription = Subscription::firstOrNew(['restaurant_id' => $restaurant->id]);
            if (!$subscription->exists) {
                $subscription->monthly_amount = $data['amount'] ?? 0;
                $subscription->status = SubscriptionStatus::TRIAL;
                $subscription->save();
            }

            $paidAt = isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : today();

            // O novo período começa onde o anterior terminou (ou hoje, se nunca pago
            // ou já vencido) e dura 1 mês.
            $periodStart = ($subscription->paid_until && $subscription->paid_until->gte(today()))
                ? $subscription->paid_until->copy()
                : today();
            $periodEnd = $periodStart->copy()->addMonthNoOverflow();

            $payment = SubscriptionPayment::create([
                'restaurant_id' => $restaurant->id,
                'amount'        => $data['amount'] ?? $subscription->monthly_amount,
                'paid_at'       => $paidAt,
                'period_start'  => $periodStart,
                'period_end'    => $periodEnd,
                'method'        => $data['method'] ?? 'manual',
                'note'          => $data['note'] ?? null,
                'recorded_by'   => $request->user()->id,
            ]);

            $subscription->paid_until = $periodEnd;
            $subscription->status = SubscriptionStatus::ACTIVE;
            $subscription->save();

            // Reativa o restaurante se estava bloqueado.
            if (!$restaurant->is_active) {
                $restaurant->update(['is_active' => true]);
            }

            return compact('subscription', 'payment');
        });

        return response()->json([
            'message'      => 'Pagamento registrado.',
            'subscription' => $this->subscriptionData($result['subscription']),
            'payment'      => $result['payment'],
        ], 201);
    }

    private function subscriptionData(Subscription $s): array
    {
        return [
            'restaurant_id'  => $s->restaurant_id,
            'monthly_amount' => $s->monthly_amount,
            'billing_day'    => $s->billing_day,
            'paid_until'     => optional($s->paid_until)->toDateString(),
            'status'         => $s->status->value,
            'status_label'   => $s->status->label(),
            'is_overdue'     => $s->isOverdue(),
            'notes'          => $s->notes,
        ];
    }
}
