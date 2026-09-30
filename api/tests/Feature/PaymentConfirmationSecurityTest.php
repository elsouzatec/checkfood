<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\SessionStatus;
use App\Enums\UserRole;
use App\Models\AppSession;
use App\Models\Category;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Pagamento só vira "pago" com prova: gateway confirmado no servidor ou a equipe
 * atestando o recebimento. O cliente da mesa não pode se auto-quitar.
 *
 * Cenário: conta de R$ 100 com pagamento pendente enviado ao terminal Mercado Pago.
 */
class PaymentConfirmationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private User $client;

    private User $waiter;

    private AppSession $session;

    private Payment $payment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create([
            'terminal_integration_enabled' => true,
            'terminal_acquirer'            => 'mercadopago',
            'allow_client_payment'         => false,
            'mercadopago_access_token'     => 'TEST-token',
        ]);

        $this->client = User::factory()->create(['role' => UserRole::CLIENT]);
        $this->waiter = User::factory()->forRestaurant($this->restaurant)->create(['role' => UserRole::WAITER]);

        $category = Category::factory()->for($this->restaurant)->create();
        $product = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $category->id,
            'name'          => 'Prato',
            'price'         => 100,
        ]);
        $table = Table::create([
            'restaurant_id' => $this->restaurant->id,
            'number'        => 1,
            'qr_code_token' => (string) Str::uuid(),
        ]);

        $this->session = AppSession::create([
            'restaurant_id' => $this->restaurant->id,
            'table_id'      => $table->id,
            'user_id'       => $this->client->id,
            'session_token' => (string) Str::uuid(),
            'status'        => SessionStatus::CLOSING,
        ]);

        $order = Order::create([
            'restaurant_id' => $this->restaurant->id,
            'session_id'    => $this->session->id,
            'table_id'      => $table->id,
            'user_id'       => $this->client->id,
            'status'        => OrderStatus::ENTREGUE,
        ]);
        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $product->id,
            'quantity'   => 1,
            'unit_price' => 100,
        ]);

        // Estado após POST /deep-link: pendente, enviado ao terminal.
        $this->payment = Payment::create([
            'restaurant_id' => $this->restaurant->id,
            'session_id'    => $this->session->id,
            'amount'        => 100,
            'method'        => 'cartao',
            'status'        => 'pending',
            'gateway'       => 'mercadopago',
            'created_by'    => 'waiter',
        ]);
    }

    private function fakeMercadoPago(array $body, int $status = 200): void
    {
        Http::fake(['api.mercadopago.com/*' => Http::response($body, $status)]);
    }

    private function confirmPos(User $user, string $terminalPaymentId = 'mp-123')
    {
        return $this->actingAs($user)->postJson("/api/payments/{$this->payment->id}/confirm-pos", [
            'status'              => 'approved',
            'terminal_payment_id' => $terminalPaymentId,
            'external_reference'  => (string) $this->payment->id,
        ]);
    }

    private function assertStillPending(): void
    {
        $this->assertSame('pending', $this->payment->fresh()->status);
        $this->assertSame(SessionStatus::CLOSING, $this->session->fresh()->status);
    }

    // ── confirm-pos ─────────────────────────────────────────

    public function test_cliente_nao_confirma_pagamento_no_terminal(): void
    {
        Http::fake();

        $this->confirmPos($this->client)->assertForbidden();

        $this->assertStillPending();
        Http::assertNothingSent();
    }

    public function test_garcom_com_aprovacao_confirmada_na_adquirente_fecha_a_conta(): void
    {
        $this->fakeMercadoPago([
            'status'             => 'approved',
            'external_reference' => (string) $this->payment->id,
            'transaction_amount' => 100.0,
        ]);

        $this->confirmPos($this->waiter)->assertOk();

        $payment = $this->payment->fresh();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('mp-123', $payment->gateway_payment_id);
        $this->assertSame(SessionStatus::CLOSED, $this->session->fresh()->status);
    }

    public function test_approved_forjado_sem_aprovacao_na_adquirente_nao_paga(): void
    {
        $this->fakeMercadoPago(['status' => 'rejected', 'external_reference' => (string) $this->payment->id, 'transaction_amount' => 100.0]);

        $this->confirmPos($this->waiter)->assertStatus(422);

        $this->assertStillPending();
    }

    public function test_transacao_de_outro_pagamento_nao_serve(): void
    {
        $this->fakeMercadoPago(['status' => 'approved', 'external_reference' => '999', 'transaction_amount' => 100.0]);

        $this->confirmPos($this->waiter)->assertStatus(422);

        $this->assertStillPending();
    }

    public function test_transacao_com_valor_menor_nao_serve(): void
    {
        $this->fakeMercadoPago(['status' => 'approved', 'external_reference' => (string) $this->payment->id, 'transaction_amount' => 1.0]);

        $this->confirmPos($this->waiter)->assertStatus(422);

        $this->assertStillPending();
    }

    public function test_falha_na_api_da_adquirente_nao_paga(): void
    {
        $this->fakeMercadoPago(['message' => 'not found'], 404);

        $this->confirmPos($this->waiter)->assertStatus(422);

        $this->assertStillPending();
    }

    public function test_transacao_ja_usada_nao_quita_outro_pagamento(): void
    {
        Payment::create([
            'restaurant_id'      => $this->restaurant->id,
            'session_id'         => $this->session->id,
            'amount'             => 100,
            'status'             => 'paid',
            'gateway_payment_id' => 'mp-123',
        ]);
        $this->fakeMercadoPago(['status' => 'approved', 'external_reference' => (string) $this->payment->id, 'transaction_amount' => 100.0]);

        $this->confirmPos($this->waiter)->assertStatus(422);

        $this->assertStillPending();
    }

    public function test_terminal_desligado_recusa_callback(): void
    {
        $this->restaurant->update(['terminal_integration_enabled' => false]);
        Http::fake();

        $this->confirmPos($this->waiter)->assertStatus(422);

        $this->assertStillPending();
        Http::assertNothingSent();
    }

    public function test_pagamento_nao_enviado_ao_terminal_recusa_callback(): void
    {
        $this->payment->update(['gateway' => null, 'method' => null]);
        Http::fake();

        $this->confirmPos($this->waiter)->assertStatus(422);

        $this->assertStillPending();
    }

    // ── pay (dinheiro/cartão) ───────────────────────────────

    public function test_cliente_nao_marca_dinheiro_como_pago(): void
    {
        $this->actingAs($this->client)
            ->postJson("/api/payments/{$this->payment->id}/pay", ['method' => 'dinheiro'])
            ->assertForbidden();

        $this->assertStillPending();
    }

    public function test_cliente_nao_marca_cartao_como_pago(): void
    {
        $this->actingAs($this->client)
            ->postJson("/api/payments/{$this->payment->id}/pay", ['method' => 'cartao'])
            ->assertForbidden();

        $this->assertStillPending();
    }

    public function test_garcom_registra_dinheiro_recebido(): void
    {
        $this->actingAs($this->waiter)
            ->postJson("/api/payments/{$this->payment->id}/pay", ['method' => 'dinheiro'])
            ->assertOk();

        $this->assertSame('paid', $this->payment->fresh()->status);
    }

    // ── close (cliente) ─────────────────────────────────────

    public function test_cliente_nao_fecha_conta_se_restaurante_nao_libera_pagamento_pelo_app(): void
    {
        $this->session->update(['status' => SessionStatus::ACTIVE]);

        $this->actingAs($this->client)
            ->postJson("/api/sessions/{$this->session->id}/close")
            ->assertForbidden();

        $this->assertSame(SessionStatus::ACTIVE, $this->session->fresh()->status);
    }
}
