<?php

namespace Tests\Feature;

use App\Enums\CashierShiftStatus;
use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Enums\UserRole;
use App\Models\AppSession;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Entrega (pedido sem mesa) — só para conta do restaurante, com entrega ligada.
 *
 * Invariantes: o pedido só vira entrega com intenção explícita; quem está numa
 * mesa não pede entrega; preço vem do cardápio; entrega não aparece para o
 * garçom; a taxa entra na conta do caixa.
 */
class DeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Product $prato;

    private Product $bebida;

    private Table $table;

    private User $cashier;

    private User $waiter;

    private string $clientToken;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create([
            'slug'               => 'casa-a',
            'delivery_enabled'   => true,
            'delivery_fee'       => 7.50,
            'delivery_min_order' => 30,
        ]);

        $cozinha = Category::factory()->for($this->restaurant)->create(['preparation_area' => 'cozinha']);
        $bar     = Category::factory()->for($this->restaurant)->create(['preparation_area' => 'bar']);

        $this->prato  = Product::create(['restaurant_id' => $this->restaurant->id, 'category_id' => $cozinha->id, 'name' => 'Moqueca', 'price' => 80]);
        $this->bebida = Product::create(['restaurant_id' => $this->restaurant->id, 'category_id' => $bar->id, 'name' => 'Suco', 'price' => 10]);

        $this->table = Table::create(['restaurant_id' => $this->restaurant->id, 'number' => 4, 'qr_code_token' => (string) Str::uuid()]);

        $this->cashier = User::factory()->forRestaurant($this->restaurant)->create(['role' => UserRole::CASHIER]);
        $this->waiter  = User::factory()->forRestaurant($this->restaurant)->create(['role' => UserRole::WAITER]);

        $this->clientToken = $this->postJson('/api/client/register', [
            'restaurant_slug' => 'casa-a',
            'name'            => 'Ana',
            'phone'           => '84988887777',
            'password'        => 'senha-forte-123',
            'accept_terms'    => true,
        ])->assertCreated()->json('token');

        $this->client = User::where('phone', '84988887777')->sole();
    }

    // ─── Helpers ────────────────────────────────────────────

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function asStaff(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => ''])->actingAs($user);
    }

    private function deliveryPayload(array $overrides = []): array
    {
        // Substitui chaves inteiras (items, address...), sem mesclar listas.
        return array_replace([
            'fulfillment'    => 'delivery',
            'items'          => [
                ['product_id' => $this->prato->id, 'quantity' => 1],
                ['product_id' => $this->bebida->id, 'quantity' => 2],
            ],
            'address'        => [
                'street'    => 'Rua das Dunas',
                'number'    => '120',
                'district'  => 'Ponta Negra',
                'reference' => 'Portão azul',
            ],
            'payment_method' => 'dinheiro',
            'change_for'     => 150,
        ], $overrides);
    }

    private function placeDelivery(array $overrides = [])
    {
        return $this->as($this->clientToken)->postJson('/api/client/delivery-orders', $this->deliveryPayload($overrides));
    }

    // ─── Cliente ────────────────────────────────────────────

    public function test_cliente_com_conta_faz_pedido_de_entrega(): void
    {
        $id = $this->placeDelivery()->assertCreated()
            ->assertJsonPath('delivery.stage', 'aguardando')
            ->json('delivery.id');

        $session = AppSession::find($id);
        $this->assertTrue($session->isDelivery());
        $this->assertNull($session->table_id);
        $this->assertSame('7.50', (string) $session->delivery_fee);
        $this->assertSame('dinheiro', $session->payment_method_chosen);
        $this->assertSame('Rua das Dunas, 120 · Ponta Negra', $session->deliveryAddressLine());

        $order = Order::where('session_id', $id)->sole();
        $this->assertSame('delivery', $order->origin);
        $this->assertNull($order->table_id);
        $this->assertSame(OrderStatus::PENDENTE_VALIDACAO, $order->status);
        $this->assertEquals(100, $order->fresh('items')->total());

        // Endereço fica salvo para o próximo pedido.
        $this->as($this->clientToken)->getJson('/api/client/delivery')
            ->assertOk()
            ->assertJsonPath('delivery.enabled', true)
            ->assertJsonPath('last_address.street', 'Rua das Dunas');
    }

    public function test_pedido_so_vira_entrega_com_intencao_explicita(): void
    {
        $payload = $this->deliveryPayload();
        unset($payload['fulfillment']);

        $this->as($this->clientToken)->postJson('/api/client/delivery-orders', $payload)->assertUnprocessable();
        $this->assertSame(0, AppSession::delivery()->count());
    }

    public function test_quem_esta_numa_mesa_nao_pede_entrega(): void
    {
        $this->as($this->clientToken)->postJson('/api/client/tables/link', [
            'table_token' => $this->table->qr_code_token,
        ])->assertCreated();

        $this->placeDelivery()
            ->assertStatus(409)
            ->assertJsonPath('has_active_table', true);

        $this->as($this->clientToken)->getJson('/api/client/delivery')
            ->assertJsonPath('active_table.number', 4);
    }

    public function test_entrega_desligada_pedido_minimo_e_troco_invalido(): void
    {
        $this->placeDelivery(['items' => [['product_id' => $this->bebida->id, 'quantity' => 1]]])
            ->assertUnprocessable()
            ->assertJsonPath('below_min_order', true);

        $this->placeDelivery(['change_for' => 50])
            ->assertUnprocessable()
            ->assertJsonPath('invalid_change', true);

        $this->restaurant->update(['delivery_enabled' => false]);
        $this->placeDelivery()->assertUnprocessable()->assertJsonPath('delivery_disabled', true);
    }

    public function test_preco_vem_do_cardapio_e_produto_de_outro_restaurante_e_recusado(): void
    {
        $outro     = Restaurant::factory()->create();
        $categoria = Category::factory()->for($outro)->create();
        $alheio    = Product::create(['restaurant_id' => $outro->id, 'category_id' => $categoria->id, 'name' => 'X', 'price' => 1]);

        $this->placeDelivery(['items' => [['product_id' => $alheio->id, 'quantity' => 40]]])
            ->assertUnprocessable()
            ->assertJsonPath('unavailable_items', true);

        $this->prato->update(['is_active' => false]);
        $this->placeDelivery()->assertUnprocessable()->assertJsonPath('unavailable_items', true);
    }

    public function test_cliente_sem_conta_nao_pede_entrega(): void
    {
        $anon = $this->postJson('/api/sessions', [
            'table_token' => $this->table->qr_code_token,
            'phone'       => '84911112222',
        ])->assertCreated()->json('token');

        $this->as($anon)->postJson('/api/client/delivery-orders', $this->deliveryPayload())->assertForbidden();
    }

    public function test_entrega_em_andamento_nao_atrapalha_a_mesa(): void
    {
        $this->placeDelivery()->assertCreated();

        // Sem mesa vinculada, o fluxo da mesa não enxerga a entrega.
        $this->as($this->clientToken)->postJson('/api/orders', [
            'items' => [['product_id' => $this->prato->id, 'quantity' => 1]],
        ])->assertUnprocessable();
        $this->as($this->clientToken)->getJson('/api/client/me')->assertJsonPath('active_session', null);

        // Vincula a mesa e o pedido da mesa vai para a mesa, não para a entrega.
        $link = $this->as($this->clientToken)->postJson('/api/client/tables/link', ['table_token' => $this->table->qr_code_token])->assertCreated();
        $this->as($link->json('token'))->postJson('/api/orders', [
            'items' => [['product_id' => $this->prato->id, 'quantity' => 1]],
        ])->assertCreated()->assertJsonPath('order.table_id', $this->table->id);
    }

    // ─── Restaurante ────────────────────────────────────────

    public function test_entrega_nao_aparece_para_o_garcom(): void
    {
        $this->placeDelivery()->assertCreated();

        $this->asStaff($this->waiter)->getJson('/api/orders/pending')->assertOk()->assertJsonCount(0, 'orders');
        $this->asStaff($this->cashier)->getJson('/api/caixa/deliveries')->assertOk()->assertJsonCount(1, 'deliveries');
    }

    public function test_ciclo_completo_da_entrega_ate_o_pagamento(): void
    {
        $id = $this->placeDelivery()->json('delivery.id');

        // Não despacha antes de aceitar.
        $this->asStaff($this->cashier)->postJson("/api/caixa/deliveries/{$id}/dispatch")->assertUnprocessable();

        // Aceitar divide por setor (cozinha + bar).
        $this->asStaff($this->cashier)->postJson("/api/caixa/deliveries/{$id}/accept")
            ->assertOk()
            ->assertJsonPath('delivery.stage', 'em_preparo')
            ->assertJsonCount(2, 'delivery.orders');
        $this->assertSame(2, Order::where('session_id', $id)->where('status', OrderStatus::CONFIRMADO)->count());

        // Cozinha enxerga o pedido de entrega.
        $kitchen = User::factory()->forRestaurant($this->restaurant)->create(['role' => UserRole::KITCHEN]);
        $this->asStaff($kitchen)->getJson('/api/orders/kitchen?area=cozinha')
            ->assertOk()
            ->assertJsonPath('orders.0.origin', 'delivery');

        $this->asStaff($this->cashier)->postJson("/api/caixa/deliveries/{$id}/dispatch")
            ->assertOk()->assertJsonPath('delivery.stage', 'a_caminho');

        $this->as($this->clientToken)->getJson('/api/client/orders')
            ->assertOk()
            ->assertJsonPath("deliveries.{$id}.stage", 'a_caminho')
            ->assertJsonPath('data.0.type', 'entrega');

        $this->asStaff($this->cashier)->postJson("/api/caixa/deliveries/{$id}/delivered")
            ->assertOk()->assertJsonPath('delivery.status', SessionStatus::AT_CASHIER->value);

        // Acerto no caixa: total = 100 de itens + 7,50 de taxa.
        CashierShift::create([
            'restaurant_id'  => $this->restaurant->id,
            'cashier_id'     => $this->cashier->id,
            'status'         => CashierShiftStatus::OPEN,
            'opened_at'      => now(),
            'opening_amount' => 0,
        ]);

        $this->asStaff($this->cashier)->getJson('/api/caixa/queue')
            ->assertOk()
            ->assertJsonPath('queue.0.type', 'entrega')
            ->assertJsonPath('queue.0.total_amount', 107.5)
            ->assertJsonPath('queue.0.delivery.change_for', 150);

        $this->asStaff($this->cashier)->postJson("/api/caixa/sessions/{$id}/confirm-payment", [
            'method' => 'dinheiro',
            'amount' => 107.50,
        ])->assertOk()->assertJsonPath('session.fully_paid', true);

        $this->assertSame(SessionStatus::CLOSED, AppSession::find($id)->status);
    }

    public function test_cancelamento_e_isolamento_entre_restaurantes(): void
    {
        $id = $this->placeDelivery()->json('delivery.id');

        $outro       = Restaurant::factory()->create();
        $caixaAlheio = User::factory()->forRestaurant($outro)->create(['role' => UserRole::CASHIER]);
        $this->asStaff($caixaAlheio)->postJson("/api/caixa/deliveries/{$id}/accept")->assertNotFound();
        $this->asStaff($caixaAlheio)->getJson('/api/caixa/deliveries')->assertJsonCount(0, 'deliveries');

        $this->asStaff($this->cashier)->postJson("/api/caixa/deliveries/{$id}/cancel", ['reason' => 'Fora da área'])
            ->assertOk()->assertJsonPath('delivery.stage', 'cancelado');
        $this->assertSame(SessionStatus::CLOSED, AppSession::find($id)->status);

        // Cliente não aciona o painel de entregas.
        $this->as($this->clientToken)->postJson("/api/caixa/deliveries/{$id}/accept")->assertForbidden();
    }

    public function test_gerente_configura_a_entrega_no_admin(): void
    {
        $manager = User::factory()->forRestaurant($this->restaurant)->manager()->create();

        $this->asStaff($manager)->putJson('/api/admin/restaurant', [
            'delivery_enabled'     => false,
            'delivery_fee'         => 5,
            'delivery_min_order'   => 25,
            'delivery_eta_minutes' => 45,
            'delivery_area_note'   => 'Ponta Negra e Capim Macio',
        ])->assertOk();

        $this->restaurant->refresh();
        $this->assertFalse($this->restaurant->delivery_enabled);
        $this->assertSame(45, $this->restaurant->delivery_eta_minutes);

        $this->as($this->clientToken)->getJson('/api/client/me')
            ->assertJsonPath('restaurant.delivery.enabled', false)
            ->assertJsonPath('restaurant.delivery.area_note', 'Ponta Negra e Capim Macio');
    }
}
