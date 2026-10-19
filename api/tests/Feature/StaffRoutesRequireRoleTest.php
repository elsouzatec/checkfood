<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Enums\UserRole;
use App\Models\AppSession;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * O cliente da mesa tem token do mesmo restaurante que a equipe. As rotas de
 * operação (garçom/cozinha) precisam barrar pelo papel — conferir só o
 * restaurante deixaria o cliente ver pedidos de outras mesas e mexer neles.
 */
class StaffRoutesRequireRoleTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private User $otherClient;

    private User $waiter;

    private User $kitchen;

    private Order $otherOrder;

    private OrderItem $otherItem;

    private AppSession $otherSession;

    protected function setUp(): void
    {
        parent::setUp();

        $restaurant = Restaurant::factory()->create();

        $this->client      = User::factory()->forRestaurant($restaurant)->create(['role' => UserRole::CLIENT]);
        $this->otherClient = User::factory()->forRestaurant($restaurant)->create(['role' => UserRole::CLIENT]);
        $this->waiter      = User::factory()->forRestaurant($restaurant)->create(['role' => UserRole::WAITER]);
        $this->kitchen     = User::factory()->forRestaurant($restaurant)->create(['role' => UserRole::KITCHEN]);

        $category = Category::factory()->for($restaurant)->create();
        $product  = Product::create([
            'restaurant_id' => $restaurant->id,
            'category_id'   => $category->id,
            'name'          => 'Prato',
            'price'         => 50,
        ]);

        $myTable    = Table::create(['restaurant_id' => $restaurant->id, 'number' => 1, 'qr_code_token' => (string) Str::uuid()]);
        $otherTable = Table::create(['restaurant_id' => $restaurant->id, 'number' => 2, 'qr_code_token' => (string) Str::uuid()]);

        AppSession::create([
            'restaurant_id' => $restaurant->id,
            'table_id'      => $myTable->id,
            'user_id'       => $this->client->id,
            'session_token' => (string) Str::uuid(),
            'status'        => SessionStatus::ACTIVE,
        ]);

        // Pedido de OUTRA mesa, de outro cliente — o alvo do ataque.
        $this->otherSession = AppSession::create([
            'restaurant_id' => $restaurant->id,
            'table_id'      => $otherTable->id,
            'user_id'       => $this->otherClient->id,
            'session_token' => (string) Str::uuid(),
            'status'        => SessionStatus::ACTIVE,
        ]);
        $this->otherOrder = Order::create([
            'restaurant_id' => $restaurant->id,
            'session_id'    => $this->otherSession->id,
            'table_id'      => $otherTable->id,
            'user_id'       => $this->otherClient->id,
            'status'        => OrderStatus::PENDENTE_VALIDACAO,
        ]);
        $this->otherItem = OrderItem::create([
            'order_id'   => $this->otherOrder->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'unit_price' => 50,
        ]);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function staffRoutes(): array
    {
        return [
            'pedidos pendentes'       => ['GET', '/api/orders/pending'],
            'fila da cozinha'         => ['GET', '/api/orders/kitchen'],
            'pedidos prontos'         => ['GET', '/api/orders/ready'],
            'detalhe do pedido'       => ['GET', '/api/orders/{order}'],
            'validar pedido'          => ['PATCH', '/api/orders/{order}/validate'],
            'entrega rápida'          => ['PATCH', '/api/orders/{order}/quick-deliver'],
            'iniciar preparo'         => ['PATCH', '/api/orders/{order}/start'],
            'marcar pronto'           => ['PATCH', '/api/orders/{order}/ready'],
            'entregar'                => ['PATCH', '/api/orders/{order}/deliver'],
            'retirar e entregar'      => ['PATCH', '/api/orders/{order}/pick-and-deliver'],
            'cancelar pedido'         => ['PATCH', '/api/orders/{order}/cancel'],
            'remover item'            => ['DELETE', '/api/orders/{order}/items/{item}'],
            'chamados pendentes'      => ['GET', '/api/waiter-calls/pending'],
            'abrir sessão guest'      => ['POST', '/api/waiter/sessions'],
            'pedido pelo garçom'      => ['POST', '/api/waiter/sessions/{session}/orders'],
            'encaminhar ao caixa'     => ['POST', '/api/waiter/sessions/{session}/forward-cashier'],
            'cardápio do garçom'      => ['GET', '/api/waiter/menu'],
            'mesas'                   => ['GET', '/api/tables'],
            'pedidos da mesa'         => ['GET', '/api/tables/{table}/orders'],
            'configurações da equipe' => ['GET', '/api/restaurant/settings'],
        ];
    }

    private function url(string $path): string
    {
        return strtr($path, [
            '{order}'   => $this->otherOrder->id,
            '{item}'    => $this->otherItem->id,
            '{session}' => $this->otherSession->id,
            '{table}'   => $this->otherOrder->table_id,
        ]);
    }

    #[DataProvider('staffRoutes')]
    public function test_cliente_nao_acessa_rota_de_operacao(string $method, string $path): void
    {
        $this->actingAs($this->client)
            ->json($method, $this->url($path))
            ->assertForbidden();
    }

    public function test_cliente_nao_altera_pedido_de_outra_mesa(): void
    {
        $this->actingAs($this->client)->patchJson("/api/orders/{$this->otherOrder->id}/cancel")->assertForbidden();
        $this->actingAs($this->client)->deleteJson("/api/orders/{$this->otherOrder->id}/items/{$this->otherItem->id}")->assertForbidden();

        $this->assertSame(OrderStatus::PENDENTE_VALIDACAO, $this->otherOrder->fresh()->status);
        $this->assertNotNull($this->otherItem->fresh());
    }

    public function test_equipe_continua_acessando_as_rotas_do_seu_app(): void
    {
        $this->actingAs($this->waiter)->getJson('/api/orders/pending')->assertOk();
        $this->actingAs($this->waiter)->getJson('/api/tables')->assertOk();
        $this->actingAs($this->waiter)->patchJson("/api/orders/{$this->otherOrder->id}/validate")->assertOk();

        $this->actingAs($this->kitchen)->getJson('/api/orders/kitchen')->assertOk();
        $this->actingAs($this->kitchen)->getJson('/api/orders/ready')->assertOk();
    }

    public function test_garcom_nao_usa_rotas_da_cozinha_e_vice_versa(): void
    {
        $this->actingAs($this->waiter)->getJson('/api/orders/kitchen')->assertForbidden();
        $this->actingAs($this->kitchen)->patchJson("/api/orders/{$this->otherOrder->id}/validate")->assertForbidden();
    }

    public function test_cliente_mantem_as_rotas_da_propria_mesa(): void
    {
        $this->actingAs($this->client)->getJson('/api/orders/my-session')->assertOk();
        $this->actingAs($this->client)->getJson('/api/sessions/check')->assertOk();
        $this->actingAs($this->client)->getJson('/api/waiter-calls/status')->assertOk();
    }
}
