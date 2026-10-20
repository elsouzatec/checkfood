<?php

namespace App\Services\Delivery;

use RuntimeException;

/** Regra de entrega violada — o controller devolve status + chave para o app. */
class DeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?string $key = null,
    ) {
        parent::__construct($message);
    }

    public function toResponse(): array
    {
        $body = ['message' => $this->getMessage()];
        if ($this->key) {
            $body[$this->key] = true;
        }

        return $body;
    }
}
