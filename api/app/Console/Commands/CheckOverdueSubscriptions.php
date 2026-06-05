<?php

namespace App\Console\Commands;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Suspende restaurantes com mensalidade vencida (paid_until anterior a hoje).
 * Idempotente: rode quantas vezes quiser. A reativação acontece ao registrar
 * pagamento (PlatformBillingController::pay).
 */
class CheckOverdueSubscriptions extends Command
{
    protected $signature = 'billing:check-overdue';

    protected $description = 'Suspende restaurantes com mensalidade vencida';

    public function handle(): int
    {
        $suspended = 0;

        Subscription::with('restaurant')
            ->where('status', '!=', SubscriptionStatus::SUSPENDED->value)
            ->whereNotNull('paid_until')
            ->whereDate('paid_until', '<', today())
            ->each(function (Subscription $sub) use (&$suspended) {
                DB::transaction(function () use ($sub, &$suspended) {
                    $sub->update(['status' => SubscriptionStatus::SUSPENDED]);

                    if ($sub->restaurant && $sub->restaurant->is_active) {
                        $sub->restaurant->update(['is_active' => false]);
                    }
                    $suspended++;
                });

                $this->warn("Suspenso: restaurante #{$sub->restaurant_id} (vencido em {$sub->paid_until->toDateString()})");
            });

        $this->info("Concluído. {$suspended} restaurante(s) suspenso(s).");

        return self::SUCCESS;
    }
}
