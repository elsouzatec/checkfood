<?php

namespace App\Enums;

enum TableStatus: string
{
    case LIVRE = 'livre';
    case OCUPADA = 'ocupada';
    case RESERVADA = 'reservada';
    case INATIVA = 'inativa';
}
