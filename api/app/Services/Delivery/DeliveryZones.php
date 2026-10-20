<?php

namespace App\Services\Delivery;

use App\Models\Restaurant;

/**
 * Cotação da entrega pela distância da loja até o ponto do cliente, em linha
 * reta (haversine) — porte da regra do CheckAutomate (DeliveryFeeService):
 *   0 → free_km: grátis · free_km → max_km: taxa · > max_km: não entrega.
 *
 * A cotação é sempre feita no servidor: a taxa enviada pelo app é ignorada.
 */
class DeliveryZones
{
    public function quote(Restaurant $restaurant, ?float $lat, ?float $lng): DeliveryQuote
    {
        $zones = $restaurant->deliveryZones();

        if ($zones === null) {
            return new DeliveryQuote('flat', (float) $restaurant->delivery_fee);
        }

        if ($lat === null || $lng === null) {
            return new DeliveryQuote('unknown');
        }

        $km = self::distanceKm($zones['lat'], $zones['lng'], $lat, $lng);

        if ($km <= $zones['free_km']) {
            return new DeliveryQuote('free', 0.0, $km);
        }

        if ($km <= $zones['max_km']) {
            return new DeliveryQuote('paid', $zones['fee'], $km);
        }

        return new DeliveryQuote('out_of_range', 0.0, $km);
    }

    /** Distância em km entre dois pontos, em linha reta (fórmula de haversine). */
    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0; // km
        $dLat  = deg2rad($lat2 - $lat1);
        $dLng  = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
