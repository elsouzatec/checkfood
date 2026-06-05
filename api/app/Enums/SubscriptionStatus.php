<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case TRIAL     = 'trial';      // configurado, ainda sem pagamento registrado
    case ACTIVE    = 'active';     // em dia (paid_until no futuro)
    case SUSPENDED = 'suspended';  // vencido e bloqueado

    public function label(): string
    {
        return match ($this) {
            self::TRIAL     => 'Em avaliação',
            self::ACTIVE    => 'Em dia',
            self::SUSPENDED => 'Suspenso por inadimplência',
        };
    }
}
