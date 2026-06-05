<?php
namespace App\Enums;

enum CashierShiftStatus: string
{
    case OPEN   = 'open';
    case CLOSED = 'closed';
}
