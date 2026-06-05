<?php
namespace App\Enums;

enum SessionStatus: string
{
    case ACTIVE     = 'active';
    case CLOSING    = 'closing';      // pagamento online em andamento (PIX, etc via app cliente)
    case AT_CASHIER = 'at_cashier';   // NOVO — garçom encaminhou ao caixa, aguardando confirmação
    case CLOSED     = 'closed';
}
