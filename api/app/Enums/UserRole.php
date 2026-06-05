<?php
namespace App\Enums;

enum UserRole: string
{
    case CLIENT      = 'client';
    case WAITER      = 'waiter';
    case KITCHEN     = 'kitchen';
    case CASHIER     = 'cashier';      // operador do app caixa
    case MANAGER     = 'manager';      // gerente do restaurante (tenant admin)
    case SUPER_ADMIN = 'super_admin';  // dono da plataforma — sem restaurant_id, cross-tenant
}
