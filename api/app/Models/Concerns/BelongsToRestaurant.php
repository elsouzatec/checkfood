<?php

namespace App\Models\Concerns;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Blindagem de multi-tenancy.
 *
 * Aplica um escopo global que restringe todas as queries ao restaurante do
 * usuário autenticado, e preenche automaticamente o restaurant_id na criação.
 *
 * O escopo é NO-OP em dois casos, preservando o comportamento existente:
 *   - requisições sem autenticação (cardápio público, login, webhooks);
 *   - usuários super_admin, que operam cross-tenant.
 *
 * NÃO deve ser aplicado ao model User: a resolução do usuário autenticado
 * (Sanctum) consulta a tabela users e geraria recursão no escopo.
 */
trait BelongsToRestaurant
{
    public static function bootBelongsToRestaurant(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->restaurant_id) && ($id = static::currentRestaurantId()) !== null) {
                $model->restaurant_id = $id;
            }
        });

        static::addGlobalScope('restaurant', function (Builder $builder): void {
            $id = static::currentRestaurantId();
            if ($id !== null) {
                $builder->where($builder->getModel()->getTable() . '.restaurant_id', $id);
            }
        });
    }

    /**
     * Restaurante do contexto atual, ou null quando não há tenant a aplicar
     * (público, login ou super_admin).
     */
    protected static function currentRestaurantId(): ?int
    {
        $user = auth()->user();

        if (!$user || $user->role === UserRole::SUPER_ADMIN) {
            return null;
        }

        return $user->restaurant_id;
    }

    /**
     * Escapa do escopo de restaurante para uma query pontual.
     */
    public function scopeWithoutRestaurantScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope('restaurant');
    }
}
