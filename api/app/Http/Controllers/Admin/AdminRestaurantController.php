<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Restaurant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AdminRestaurantController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $restaurant = Restaurant::findOrFail($request->user()->restaurant_id);

        // Não devolve o token/secret; apenas flags de presença para a UI
        $payload = $restaurant->toArray();
        $payload['mercadopago_configured']     = !empty($restaurant->mercadopago_access_token);
        $payload['mercadopago_webhook_ready']  = !empty($restaurant->mercadopago_webhook_secret);

        return response()->json(['restaurant' => $payload]);
    }

    /**
     * PUT /api/admin/restaurant/payment-gateway
     * Atualiza apenas credenciais de gateway. Campos opcionais —
     * string vazia limpa o valor; ausente preserva o valor atual.
     */
    public function updatePaymentGateway(Request $request): JsonResponse
    {
        $restaurant = Restaurant::findOrFail($request->user()->restaurant_id);

        $data = $request->validate([
            'mercadopago_access_token'   => 'nullable|string|max:500',
            'mercadopago_webhook_secret' => 'nullable|string|max:500',
            'mercadopago_public_key'     => 'nullable|string|max:255',
        ]);

        $update = [];
        foreach (['mercadopago_access_token', 'mercadopago_webhook_secret', 'mercadopago_public_key'] as $field) {
            if ($request->exists($field)) {
                $update[$field] = $data[$field] ?: null;
            }
        }

        if ($update) {
            $restaurant->update($update);
        }

        return response()->json([
            'message' => 'Credenciais atualizadas.',
            'mercadopago_configured'    => !empty($restaurant->fresh()->mercadopago_access_token),
            'mercadopago_webhook_ready' => !empty($restaurant->fresh()->mercadopago_webhook_secret),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $restaurant = Restaurant::findOrFail($request->user()->restaurant_id);

        $data = $request->validate([
            'name'                         => 'sometimes|string|max:150',
            'address'                      => 'nullable|string|max:255',
            'phone'                        => 'nullable|string|max:20',
            'wifi_network'                 => 'nullable|string|max:100',
            'wifi_password'                => 'nullable|string|max:100',
            'wifi_on_plaque'               => 'boolean',
            'qr_phrase'                    => 'nullable|string|max:150',
            'show_total_during_service'    => 'boolean',
            'show_order_progress'          => 'boolean',
            'terminal_integration_enabled' => 'boolean',
            'terminal_acquirer'            => 'sometimes|string|in:mercadopago,pagseguro,stone',
            'allow_client_payment'         => 'boolean',
            'cashier_mode'                 => 'sometimes|boolean',
            'tip_enabled'                  => 'sometimes|boolean',
            'tip_default_percent'          => 'sometimes|numeric|min:0|max:100',
            'print_enabled'                => 'sometimes|boolean',
        ]);

        // $request->boolean() garante conversão correta independente do formato recebido
        if ($request->has('cashier_mode')) {
            $data['cashier_mode'] = $request->boolean('cashier_mode');
        }
        if ($request->has('tip_enabled')) {
            $data['tip_enabled'] = $request->boolean('tip_enabled');
        }
        if ($request->has('print_enabled')) {
            $data['print_enabled'] = $request->boolean('print_enabled');
        }

        $restaurant->update($data);

        return response()->json([
            'message'    => 'Configurações salvas.',
            'restaurant' => $restaurant->fresh(),
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => 'required|image|max:2048']);
        $restaurant = Restaurant::findOrFail($request->user()->restaurant_id);

        if ($restaurant->logo_url) {
            $oldPath = str_replace('/storage/', '', $restaurant->logo_url);
            Storage::disk('public')->delete($oldPath);
        }

        $path = $request->file('logo')->store('logos', 'public');

        // Mesma guarda do upload de produto: store() devolve false silenciosamente
        // quando não consegue gravar no disco público.
        if (!$path) {
            Log::error('Falha ao gravar logo do restaurante.', [
                'restaurant_id' => $restaurant->id,
                'dir'           => Storage::disk('public')->path('logos'),
            ]);

            return response()->json([
                'message' => 'Não foi possível salvar a imagem no servidor (permissão de escrita).',
            ], 500);
        }

        $restaurant->update(['logo_url' => '/storage/' . $path]);

        return response()->json([
            'message'  => 'Imagem atualizada.',
            'logo_url' => $restaurant->logo_url,
        ]);
    }

    public function removeLogo(Request $request): JsonResponse
    {
        $restaurant = Restaurant::findOrFail($request->user()->restaurant_id);

        if ($restaurant->logo_url) {
            $oldPath = str_replace('/storage/', '', $restaurant->logo_url);
            Storage::disk('public')->delete($oldPath);
            $restaurant->update(['logo_url' => null]);
        }

        return response()->json(['message' => 'Logo removida.']);
    }
}
