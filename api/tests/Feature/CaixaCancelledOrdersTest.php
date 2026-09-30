<?php

namespace Tests\Feature;

use App\Enums\CashierShiftStatus;
use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Models\AppSession;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use App\Services\Payment\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pedidos cancelados não entram na conta do caixa. A fila, o detalhe da sessão e
 * o confirm-payment devem usar o mesmo total que o PIX (PaymentService).
 *
 * Cenário: conta de R$ 100 válidos + R$ 40 de um pedido cancelado.
 */
class CaixaCancelledOrdersTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private AppSession $session;

    protected function setUp(): void
    {
        parent::setUp();

        $restaurant = Restaurant::factory()->create();
        $this->manager = User::factory()->manager()->forRestaurant($restaurant)->create();

        $category = Category::factory()->for($restaurant)->create();
        $product = Product::create([
            'restaurant_id' => $restaurant->id,
            'category_id'   => $category->id,
            'name'          => 'Prato',
            'price'         => 50,
        ]);
        $table = Table::create([
            'restaurant_id' => $restaurant->id,
            'number'        => 1,
            'qr_code_token' => (string) Str::uuid(),
        ]);

        $this->session = AppSession::create([
            'restaurant_id' => $restaurant->id,
            'table_id'      => $table->id,
            'user_id'       => $this->manager->id,
            'session_token' => (string) Str::uuid(),
            'status'        => SessionStatus::AT_CASHIER,
            'at_cashier_at' => now(),
        ]);

        $this->makeOrder($product, OrderStatus::ENTREGUE, quantity: 2, unitPrice: 50);  // R$ 100
        $this->makeOrder($product, OrderStatus::CANCELADO, quantity: 1, unitPrice: 40); // R$ 40

        CashierShift::create([
            'restaurant_id'  => $restaurant->id,
            'cashier_id'     => $this->manager->id,
            'opening_amount' => 0,
            'status'         => CashierShiftStatus::OPEN,
            'opened_at'      => now(),
        ]);
    }

    private function makeOrder(Product $product, OrderStatus $status, int $quantity, float $unitPrice): void
    {
        $order = Order::create([
            'restaurant_id' => $this->session->restaurant_id,
            'session_id'    => $this->session->id,
            'table_id'      => $this->session->table_id,
            'user_id'       => $this->manager->id,
            'status'        => $status,
        ]);

        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
        ]);
    }

    public function test_fila_nao_soma_pedido_cancelado(): void
    {
        $response = $this->actingAs($this->manager)->getJson('/api/caixa/queue');

        $response->assertOk();
        $this->assertEquals(100, $response->json('queue.0.total_amount'));
        $this->assertSame(1, $response->json('queue.0.order_count'));
    }

    public function test_detalhe_lista_cancelado_mas_nao_soma(): void
    {
        $response = $this->actingAs($this->manager)->getJson("/api/caixa/sessions/{$this->session->id}");

        $response->assertOk();
        $this->assertCount(2, $response->json('orders'));
        $this->assertContains('cancelado', array_column($response->json('orders'), 'status'));
        $this->assertEquals(100, $response->json('total_amount'));
        $this->assertEquals(100, $response->json('remaining_amount'));
    }

    public function test_pagar_total_valido_fecha_a_conta(): void
    {
        $response = $this->actingAs($this->manager)->postJson(
            "/api/caixa/sessions/{$this->session->id}/confirm-payment",
            ['amount' => 100, 'method' => 'dinheiro'],
        );

        $response->assertOk();
        $this->assertTrue($response->json('session.fully_paid'));
        $this->assertEquals(0, $response->json('session.remaining_amount'));
        $this->assertSame(SessionStatus::CLOSED, $this->session->fresh()->status);
    }

    public function test_nao_aceita_cobrar_pedido_cancelado(): void
    {
        $response = $this->actingAs($this->manager)->postJson(
            "/api/caixa/sessions/{$this->session->id}/confirm-payment",
            ['amount' => 140, 'method' => 'dinheiro'],
        );

        $response->assertStatus(422);
        $this->assertEquals(100, $response->json('remaining'));
    }

    public function test_total_do_caixa_bate_com_o_do_pix(): void
    {
        $pixTotal = app(PaymentService::class)->calculateSessionTotal($this->session);

        $response = $this->actingAs($this->manager)->getJson("/api/caixa/sessions/{$this->session->id}");

        $this->assertEquals($pixTotal, $response->json('total_amount'));
    }
}
