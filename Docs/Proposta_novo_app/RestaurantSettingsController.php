<?php

namespace App\Http\Controllers;

use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RestaurantSettingsController extends Controller
{
    /**
     * GET /api/restaurant/settings
     * Retorna feature flags não-sensíveis para o staff (garçom, cozinha, gerente, caixa).
     *
     * O `cashier_mode` é importante para o app do garçom alternar o botão
     * "Receber Pagamento" para "Encaminhar ao Caixa" quando ativo.
     */
    public function staff(Request $request): JsonResponse
    {
        $restaurant = Restaurant::findOrFail($request->user()->restaurant_id);

        return response()->json([
            'terminal_integration_enabled' => (bool) $restaurant->terminal_integration_enabled,
            'terminal_acquirer'            => $restaurant->terminal_acquirer ?? 'mercadopago',
            'allow_client_payment'         => (bool) $restaurant->allow_client_payment,
            'show_total_during_service'    => (bool) $restaurant->show_total_during_service,
            'tip_enabled'                  => (bool) $restaurant->tip_enabled,
            'tip_default_percent'          => (float) ($restaurant->tip_default_percent ?? 10),
            'print_enabled'                => (bool) $restaurant->print_enabled,
            'cashier_mode'                 => (bool) $restaurant->cashier_mode,
        ]);
    }

    /**
     * PATCH /api/admin/restaurant/settings
     *
     * Atualiza configurações do restaurante. Apenas role manager.
     * Aceita atualização parcial — só os campos enviados são alterados.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'show_total_during_service'    => 'sometimes|boolean',
            'show_order_progress'          => 'sometimes|boolean',
            'terminal_integration_enabled' => 'sometimes|boolean',
            'terminal_acquirer'            => 'sometimes|string|max:30',
            'tip_enabled'                  => 'sometimes|boolean',
            'tip_default_percent'          => 'sometimes|numeric|min:0|max:100',
            'allow_client_payment'         => 'sometimes|boolean',
            'print_enabled'                => 'sometimes|boolean',
            'cashier_mode'                 => 'sometimes|boolean',
            'wifi_network'                 => 'sometimes|nullable|string|max:64',
            'wifi_password'                => 'sometimes|nullable|string|max:64',
            'wifi_on_plaque'               => 'sometimes|boolean',
            'qr_phrase'                    => 'sometimes|nullable|string|max:120',
        ]);

        $restaurant = Restaurant::findOrFail($request->user()->restaurant_id);
        $restaurant->update($validated);

        return response()->json([
            'message'    => 'Configurações atualizadas.',
            'restaurant' => $restaurant->fresh(),
        ]);
    }
}
