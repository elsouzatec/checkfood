<?php

namespace App\Services\Session;

use RuntimeException;

/** Mesa em uso por outro cliente ou por uma sessão aberta pelo garçom. */
class TableUnavailableException extends RuntimeException
{
}
