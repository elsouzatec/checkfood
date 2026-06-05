<?php

namespace App\Services\Payment;

use App\Models\Restaurant;

class PaymentGatewayFactory
{
    /**
     * Resolve o gateway de pagamento com credenciais do tenant.
     * Fallback: se o restaurante não tiver token próprio, usa o do env
     * (modo legacy — útil durante a transição multi-tenant).
     */
    public function forRestaurant(Restaurant $restaurant): PaymentGatewayInterface
    {
        return new MercadoPagoGateway($restaurant->mercadopago_access_token);
    }

    /**
     * Resolve o gateway a partir apenas do token (usado pelo webhook,
     * quando o tenant já foi identificado via Payment).
     */
    public function withToken(?string $accessToken): PaymentGatewayInterface
    {
        return new MercadoPagoGateway($accessToken);
    }
}
