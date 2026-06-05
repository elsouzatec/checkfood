<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDENTE_VALIDACAO = 'pendente_validacao';
    case CONFIRMADO = 'confirmado';
    case PREPARANDO = 'preparando';
    case PRONTO = 'pronto';
    case ENTREGUE = 'entregue';
    case PAGO = 'pago';
    case CANCELADO = 'cancelado';

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE_VALIDACAO => 'Pendente de Validação',
            self::CONFIRMADO => 'Confirmado',
            self::PREPARANDO => 'Preparando',
            self::PRONTO => 'Pronto',
            self::ENTREGUE => 'Entregue',
            self::PAGO => 'Pago',
            self::CANCELADO => 'Cancelado',
        };
    }

    /**
     * Retorna os próximos status válidos a partir do atual.
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PENDENTE_VALIDACAO => [self::CONFIRMADO, self::CANCELADO],
            self::CONFIRMADO => [self::PREPARANDO, self::CANCELADO],
            self::PREPARANDO => [self::PRONTO, self::CANCELADO],
            self::PRONTO => [self::ENTREGUE, self::CANCELADO],
            self::ENTREGUE => [self::PAGO],
            self::PAGO => [],
            self::CANCELADO => [],
        };
    }

    public function canTransitionTo(self $newStatus): bool
    {
        return in_array($newStatus, $this->allowedTransitions());
    }
}
