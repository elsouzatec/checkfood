<?php

namespace App\Services\Delivery;

/**
 * Taxa de entrega para um ponto (mesma regra do CheckAutomate).
 *
 *   flat         — restaurante sem zonas: taxa fixa em qualquer lugar.
 *   free         — dentro do raio grátis.
 *   paid         — entre o raio grátis e o máximo: cobra a taxa.
 *   out_of_range — além do raio máximo: NÃO entrega.
 *   unknown      — zonas ligadas, mas o cliente não enviou a localização.
 */
readonly class DeliveryQuote
{
    public function __construct(
        public string $status,
        public float $fee = 0.0,
        public ?float $distanceKm = null,
    ) {}

    public function deliverable(): bool
    {
        return in_array($this->status, ['flat', 'free', 'paid'], true);
    }

    public function toArray(): array
    {
        return [
            'status'      => $this->status,
            'fee'         => round($this->fee, 2),
            'distance_km' => $this->distanceKm !== null ? round($this->distanceKm, 2) : null,
        ];
    }
}
