<?php

namespace App\Enums;

/**
 * Tipo da sessão: visita a uma mesa ou pedido para entrega (sem mesa).
 * A entrega reaproveita a sessão para agrupar os pedidos (divididos por
 * setor na aprovação), a taxa de entrega e o acerto no caixa.
 */
enum SessionType: string
{
    case MESA    = 'mesa';
    case ENTREGA = 'entrega';
}
