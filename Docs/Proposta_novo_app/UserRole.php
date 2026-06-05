<?php
namespace App\Enums;

enum UserRole: string
{
    case CLIENT  = 'client';
    case WAITER  = 'waiter';
    case KITCHEN = 'kitchen';
    case CASHIER = 'cashier';   // NOVO — operador do app caixa
    case MANAGER = 'manager';
}
