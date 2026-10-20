<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Enums\TableStatus;
use App\Enums\UserRole;
use App\Models\AppSession;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Services\Delivery\DeliveryZones;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Entrega por distância (regra do CheckAutomate) e PIX pelo app.
 *
 * Loja em Belém; ~0,009° de latitude ≈ 1 km. Faixas: grátis até 2 km, taxa de
 * R$ 6 até 5 km, além disso não entrega.
 */
class DeliveryZonesPixTest extends TestCase
{
    use RefreshDatabase;

    private const LAT = -1.4558;

    private const LNG = -48.4902;

    private Restaurant $restaurant;

    private Product $prato;

    private User $cashier;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create([
            'slug'                     => 'casa-zonas',
            'delivery_enabled'         => true,
            'delivery_fee'             => 6,
            'delivery_min_order'       => 0,
            'latitude'                 => self::LAT,
            'longitude'                => self::LNG,
            'delivery_free_km'         => 2,
            'delivery_max_km'          => 5,
            'mercadopago_access_token' => 'TEST-token',
        ]);

        $categoria   = Category::factory()->for($this->restaurant)->create();
        $this->prato = Product::create(['restaurant_id' => $this->restaurant->id, 'category_id' => $categoria->id, 'name' => 'Açaí', 'price' => 30]);

        $this->cashier = User::factory()->forRestaurant($this->restaurant)->create(['role' => UserRole::CASHIER]);

        $this->token = $this->postJson('/api/client/register', [
            'restaurant_slug' => 'casa-zonas',
            'name'            => 'Rui',
            'phone'           => '91988880000',
            'password'        => 'senha-forte-123',
            'accept_terms'    => true,
        ])->assertCreated()->json('token');
    }

    private function as(?User $staff = null): static
    {
        $this->app['auth']->forgetGuards();

        return $staff
            ? $this->withHeaders(['Authorization' => ''])->actingAs($staff)
            : $this->withToken($this->token);
    }

    /** Ponto a ~$km ao norte da loja. */
    private function pointKm(float $km): array
    {
        return ['lat' => self::LAT + $km * 0.009, 'lng' => self::LNG];
    }

    private function order(array $overrides = [])
    {
        return $this->as()->postJson('/api/client/delivery-orders', array_replace([
            'fulfillment'    => 'delivery',
            'items'          => [['product_id' => $this->prato->id, 'quantity' => 2]],
            'address'        => ['street' => 'Av. Nazaré', 'number' => '10', 'district' => 'Nazaré'],
            'location'       => $this->pointKm(1),
            'payment_method' => 'dinheiro',
        ], $overrides));
    }

    private function fakeMercadoPago(): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response([
            'id'                   => 987654,
            'status'               => 'pending',
            'point_of_interaction' => ['transaction_data' => [
                'qr_code'        => '00020126PIXCOPIAECOLA',
                'qr_code_base64' => 'iVBORw0KGgo=',
                'ticket_url'     => 'https://mpago.la/x',
            ]],
        ], 201)]);
    }

    // ─── Zonas ──────────────────────────────────────────────

    public function test_haversine_confere_com_distancia_conhecida(): void
    {
        // 1° de latitude ≈ 111,19 km
        $this->assertEqualsWithDelta(111.19, DeliveryZones::distanceKm(0, 0, 1, 0), 0.05);
    }

    public function test_cotacao_por_faixa(): void
    {
        $this->as()->postJson('/api/client/delivery/quote', $this->pointKm(1))
            ->assertOk()->assertJsonPath('quote.status', 'free')->assertJsonPath('quote.fee', 0);

        $this->as()->postJson('/api/client/delivery/quote', $this->pointKm(3.5))
            ->assertJsonPath('quote.status', 'paid')->assertJsonPath('quote.fee', 6);

        $this->as()->postJson('/api/client/delivery/quote', $this->pointKm(8))
            ->assertJsonPath('quote.status', 'out_of_range');

        // Sem zonas configuradas: taxa fixa em qualquer lugar.
        $this->restaurant->update(['latitude' => null, 'longitude' => null]);
        $this->as()->postJson('/api/client/delivery/quote', $this->pointKm(80))
            ->assertJsonPath('quote.status', 'flat')->assertJsonPath('quote.fee', 6);
    }

    public function test_taxa_e_calculada_no_servidor_pelo_ponto(): void
    {
        $free = $this->order(['location' => $this->pointKm(1)])->assertCreated();
        $this->assertEquals(0, $free->json('delivery.fee'));

        $paid = $this->order(['location' => $this->pointKm(4)])->assertCreated();
        $this->assertEquals(6, $paid->json('delivery.fee'));
        $this->assertEqualsWithDelta(4.0, $paid->json('delivery.distance_km'), 0.05);

        $session = AppSession::find($paid->json('delivery.id'));
        $this->assertStringContainsString('destination=' . $session->delivery_lat, $session->deliveryMapsUrl());
    }

    public function test_fora_da_area_e_sem_localizacao_sao_recusados(): void
    {
        $this->order(['location' => $this->pointKm(9)])
            ->assertUnprocessable()->assertJsonPath('out_of_range', true);

        $this->order(['location' => null])
            ->assertUnprocessable()->assertJsonPath('location_required', true);

        $this->assertSame(0, AppSession::delivery()->count());
    }

    public function test_gerente_configura_as_zonas(): void
    {
        $manager = User::factory()->forRestaurant($this->restaurant)->manager()->create();

        $this->as($manager)->putJson('/api/admin/restaurant', [
            'latitude' => -1.40, 'longitude' => -48.45, 'delivery_free_km' => 3, 'delivery_max_km' => 2,
        ])->assertUnprocessable();

        $this->as($manager)->putJson('/api/admin/restaurant', [
            'latitude' => -1.40, 'longitude' => -48.45, 'delivery_free_km' => 1.5, 'delivery_max_km' => 7,
        ])->assertOk();

        $this->as()->getJson('/api/client/delivery')
            ->assertJsonPath('delivery.zones.max_km', 7)
            ->assertJsonPath('delivery.zones.lat', -1.4);
    }

    // ─── PIX pelo app ───────────────────────────────────────

    public function test_pix_pelo_app_gera_cobranca_com_o_total_da_entrega(): void
    {
        $this->fakeMercadoPago();

        $res = $this->order(['payment_method' => 'pix', 'location' => $this->pointKm(4)])
            ->assertCreated()
            ->assertJsonPath('delivery.stage', 'aguardando_pagamento')
            ->assertJsonPath('payment.qr_code', '00020126PIXCOPIAECOLA');

        // 2 × 30 + taxa 6 = 66
        $this->assertEquals(66, $res->json('payment.amount'));
        Http::assertSent(fn ($r) => str_contains($r['description'], 'Entrega #'));

        // O app consegue consultar a própria cobrança.
        $this->as()->getJson('/api/payments/' . $res->json('payment.id'))->assertOk();
    }

    public function test_pix_indisponivel_sem_mercado_pago(): void
    {
        $this->restaurant->update(['mercadopago_access_token' => null]);

        $this->as()->getJson('/api/client/delivery')->assertJsonMissing(['payment_methods' => ['pix']]);
        $this->assertNotContains('pix', $this->as()->getJson('/api/client/delivery')->json('payment_methods'));

        $this->order(['payment_method' => 'pix'])
            ->assertUnprocessable()->assertJsonPath('pix_unavailable', true);
    }

    public function test_entrega_pix_so_e_aceita_depois_de_paga_e_encerra_ao_entregar(): void
    {
        $this->fakeMercadoPago();
        $res     = $this->order(['payment_method' => 'pix']);
        $id      = $res->json('delivery.id');
        $payment = Payment::find($res->json('payment.id'));

        $this->as($this->cashier)->postJson("/api/caixa/deliveries/{$id}/accept")
            ->assertUnprocessable()->assertJsonPath('awaiting_payment', true);

        // Webhook confirma: registra o pagamento, mas a entrega continua aberta.
        app(PaymentService::class)->confirmFromGateway($payment->fresh());
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(SessionStatus::ACTIVE, AppSession::find($id)->status);

        $this->as($this->cashier)->getJson('/api/caixa/deliveries')
            ->assertJsonPath('deliveries.0.paid', true)
            ->assertJsonPath('deliveries.0.stage', 'aguardando');

        $this->as($this->cashier)->postJson("/api/caixa/deliveries/{$id}/accept")->assertOk();
        $this->as($this->cashier)->postJson("/api/caixa/deliveries/{$id}/dispatch")->assertOk();
        $this->as($this->cashier)->postJson("/api/caixa/deliveries/{$id}/delivered")
            ->assertOk()
            ->assertJsonPath('delivery.status', SessionStatus::CLOSED->value)
            ->assertJsonPath('delivery.stage', 'entregue');

        // Já pago: não passa pelo acerto do caixa.
        $this->as($this->cashier)->getJson('/api/caixa/queue')->assertJsonCount(0, 'queue');
        $this->assertSame(0, Order::where('session_id', $id)->where('status', '!=', OrderStatus::PAGO->value)->count());
    }

    public function test_cancelar_entrega_cancela_cobranca_pendente(): void
    {
        $this->fakeMercadoPago();
        $res = $this->order(['payment_method' => 'pix']);

        $this->as($this->cashier)->postJson('/api/caixa/deliveries/' . $res->json('delivery.id') . '/cancel', ['reason' => 'Sem entregador'])
            ->assertOk();

        $this->assertSame('cancelled', Payment::find($res->json('payment.id'))->status);
    }

    public function test_pix_da_mesa_continua_fechando_a_conta(): void
    {
        $table   = Table::create(['restaurant_id' => $this->restaurant->id, 'number' => 1, 'qr_code_token' => (string) Str::uuid(), 'status' => TableStatus::OCUPADA]);
        $client  = User::factory()->forRestaurant($this->restaurant)->create(['role' => UserRole::CLIENT]);
        $session = AppSession::create([
            'restaurant_id' => $this->restaurant->id, 'table_id' => $table->id, 'user_id' => $client->id,
            'session_token' => (string) Str::uuid(), 'status' => SessionStatus::CLOSING,
        ]);
        $payment = Payment::create([
            'restaurant_id' => $this->restaurant->id, 'session_id' => $session->id,
            'amount' => 10, 'method' => 'pix', 'status' => 'pending',
        ]);

        app(PaymentService::class)->confirmFromGateway($payment);

        $this->assertSame(SessionStatus::CLOSED, $session->fresh()->status);
        $this->assertSame(TableStatus::LIVRE, $table->fresh()->status);
    }
}
