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
use Tests\TestCase;

/**
 * Conta do cliente — por restaurante (users.role=client com senha).
 *
 * Invariantes: a conta só enxerga o próprio restaurante e os próprios pedidos;
 * telefone com conta não entra mais na mesa só pelo número; o histórico de
 * antes do cadastro fica oculto (telefone ainda não verificado).
 */
class ClientAccountTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '84988887777';

    private const PASSWORD = 'senha-forte-123';

    private Restaurant $restaurant;

    private Restaurant $otherRestaurant;

    private Table $table;

    private Table $table2;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant      = Restaurant::factory()->create(['slug' => 'casa-a']);
        $this->otherRestaurant = Restaurant::factory()->create(['slug' => 'casa-b']);

        $this->table  = $this->makeTable($this->restaurant, 1);
        $this->table2 = $this->makeTable($this->restaurant, 2);

        $category      = Category::factory()->for($this->restaurant)->create();
        $this->product = Product::create([
            'restaurant_id' => $this->restaurant->id,
            'category_id'   => $category->id,
            'name'          => 'Moqueca',
            'price'         => 80,
        ]);
    }

    // ─── Helpers ────────────────────────────────────────────

    private function makeTable(Restaurant $restaurant, int $number): Table
    {
        return Table::create([
            'restaurant_id' => $restaurant->id,
            'number'        => $number,
            'qr_code_token' => (string) Str::uuid(),
        ]);
    }

    /** Requisição com token real (Sanctum), sem herdar o usuário da anterior. */
    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function register(array $overrides = [])
    {
        return $this->postJson('/api/client/register', $overrides + [
            'restaurant_slug' => 'casa-a',
            'name'            => 'Ana Souza',
            'phone'           => '(84) 98888-7777',
            'password'        => self::PASSWORD,
            'accept_terms'    => true,
        ]);
    }

    private function enterTableAnonymously(Table $table, string $phone = self::PHONE)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => ''])->postJson('/api/sessions', [
            'table_token' => $table->qr_code_token,
            'phone'       => $phone,
        ]);
    }

    private function orderFor(User $user, AppSession $session): Order
    {
        $order = Order::create([
            'restaurant_id' => $session->restaurant_id,
            'session_id'    => $session->id,
            'table_id'      => $session->table_id,
            'user_id'       => $user->id,
            'status'        => OrderStatus::ENTREGUE,
        ]);
        OrderItem::create([
            'order_id'   => $order->id,
            'product_id' => $this->product->id,
            'quantity'   => 1,
            'unit_price' => 80,
        ]);

        return $order;
    }

    private function closeSession(int $sessionId): void
    {
        AppSession::find($sessionId)->update(['status' => SessionStatus::CLOSED, 'closed_at' => now()]);
    }

    // ─── Cadastro ───────────────────────────────────────────

    public function test_cadastro_pelo_link_do_restaurante_cria_conta_e_loga(): void
    {
        $response = $this->register()->assertCreated();

        $this->assertSame('casa-a', $response->json('restaurant.slug'));
        $this->assertTrue($response->json('user.has_account'));
        $this->assertSame(self::PHONE, $response->json('user.phone'));
        $this->assertArrayNotHasKey('password', $response->json('user'));

        $this->as($response->json('token'))->getJson('/api/client/me')
            ->assertOk()
            ->assertJsonPath('user.name', 'Ana Souza')
            ->assertJsonPath('restaurant.slug', 'casa-a');
    }

    public function test_cadastro_pelo_qr_da_mesa_identifica_o_restaurante(): void
    {
        $this->register(['restaurant_slug' => null, 'table_token' => $this->table->qr_code_token])
            ->assertCreated()
            ->assertJsonPath('restaurant.id', $this->restaurant->id);
    }

    public function test_cadastro_exige_aceite_dos_termos_e_senha_minima(): void
    {
        $this->register(['accept_terms' => false])->assertUnprocessable();
        $this->register(['password' => '1234'])->assertUnprocessable();
        $this->assertSame(0, User::whereNotNull('registered_at')->count());
    }

    public function test_cadastro_duplicado_no_mesmo_restaurante_e_recusado(): void
    {
        $this->register()->assertCreated();
        $this->register(['password' => 'outra-senha-999'])
            ->assertUnprocessable()
            ->assertJsonPath('account_exists', true);
    }

    public function test_mesmo_telefone_tem_contas_independentes_em_restaurantes_diferentes(): void
    {
        $this->register()->assertCreated();
        $this->register(['restaurant_slug' => 'casa-b'])->assertCreated();

        $this->assertSame(2, User::where('phone', self::PHONE)->whereNotNull('registered_at')->count());
    }

    public function test_restaurante_suspenso_nao_aceita_cadastro(): void
    {
        $this->restaurant->update(['is_active' => false]);

        $this->register()->assertNotFound();
    }

    // ─── Login ──────────────────────────────────────────────

    public function test_login_com_telefone_e_senha(): void
    {
        $this->register()->assertCreated();

        $this->postJson('/api/client/login', ['phone' => '84 98888-7777', 'password' => 'errada-000'])
            ->assertUnauthorized();

        $this->postJson('/api/client/login', ['phone' => '84 98888-7777', 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('restaurant.slug', 'casa-a');
    }

    public function test_login_com_conta_em_dois_restaurantes_pede_para_escolher(): void
    {
        $this->register()->assertCreated();
        $this->register(['restaurant_slug' => 'casa-b'])->assertCreated();

        $this->postJson('/api/client/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])
            ->assertStatus(409)
            ->assertJsonPath('requires_restaurant', true)
            ->assertJsonCount(2, 'restaurants');

        $this->postJson('/api/client/login', [
            'phone' => self::PHONE, 'password' => self::PASSWORD, 'restaurant_slug' => 'casa-b',
        ])->assertOk()->assertJsonPath('restaurant.slug', 'casa-b');
    }

    public function test_login_de_cliente_nao_autentica_funcionario(): void
    {
        User::factory()->forRestaurant($this->restaurant)->manager()->create([
            'phone'    => '84911112222',
            'password' => self::PASSWORD,
        ]);

        $this->postJson('/api/client/login', ['phone' => '84911112222', 'password' => self::PASSWORD])
            ->assertUnauthorized();
    }

    public function test_conta_nao_acessa_rotas_da_equipe_e_equipe_nao_acessa_conta(): void
    {
        $token = $this->register()->json('token');
        $this->as($token)->getJson('/api/orders/pending')->assertForbidden();
        $this->as($token)->getJson('/api/admin/clients')->assertForbidden();

        $manager = User::factory()->forRestaurant($this->restaurant)->manager()->create();
        $this->app['auth']->forgetGuards();
        $this->actingAs($manager)->getJson('/api/client/me')->assertForbidden();
    }

    // ─── Mesa: entrada anônima e vínculo ───────────────────

    public function test_entrada_anonima_continua_funcionando(): void
    {
        $first = $this->enterTableAnonymously($this->table)->assertCreated();
        $this->enterTableAnonymously($this->table)->assertOk()
            ->assertJsonPath('session.id', $first->json('session.id'));

        $this->enterTableAnonymously($this->table, '84900001111')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Mesa ocupada por outro cliente.');
    }

    public function test_mesa_com_sessao_do_garcom_recusa_cliente(): void
    {
        AppSession::create([
            'restaurant_id' => $this->restaurant->id,
            'table_id'      => $this->table->id,
            'user_id'       => null,
            'guest_name'    => 'Mesa do João',
            'session_token' => (string) Str::uuid(),
            'status'        => SessionStatus::ACTIVE,
        ]);

        $this->enterTableAnonymously($this->table)
            ->assertStatus(409)
            ->assertJsonPath('message', 'Mesa ocupada. Solicite assistência ao garçom.');
        $this->assertSame(0, User::where('role', UserRole::CLIENT)->count());
    }

    public function test_telefone_com_conta_nao_entra_na_mesa_so_pelo_numero(): void
    {
        $this->register()->assertCreated();

        $this->enterTableAnonymously($this->table)
            ->assertStatus(409)
            ->assertJsonPath('requires_login', true);

        $this->assertSame(0, AppSession::count());
    }

    public function test_conta_vincula_a_mesa_e_recebe_token_da_sessao(): void
    {
        $token = $this->register()->json('token');

        $link = $this->as($token)->postJson('/api/client/tables/link', [
            'table_token' => $this->table->qr_code_token,
        ])->assertCreated();

        $this->assertSame($this->table->id, $link->json('table.id'));
        $this->assertSame('active', AppSession::find($link->json('session.id'))->status->value);

        // O token da sessão serve ao fluxo da mesa que já existe.
        $this->as($link->json('token'))->getJson('/api/sessions/check')
            ->assertOk()
            ->assertJsonPath('session_id', $link->json('session.id'));

        // Vincular de novo retoma a mesma sessão.
        $this->as($token)->postJson('/api/client/tables/link', ['table_token' => $this->table->qr_code_token])
            ->assertOk()
            ->assertJsonPath('session.id', $link->json('session.id'));
    }

    public function test_vinculo_recusa_mesa_de_outro_restaurante_e_mesa_ocupada(): void
    {
        $token = $this->register()->json('token');

        $this->as($token)->postJson('/api/client/tables/link', [
            'table_token' => $this->makeTable($this->otherRestaurant, 9)->qr_code_token,
        ])->assertStatus(409)->assertJsonPath('other_restaurant', true);

        $this->enterTableAnonymously($this->table2, '84900001111')->assertCreated();
        $this->as($token)->postJson('/api/client/tables/link', ['table_token' => $this->table2->qr_code_token])
            ->assertStatus(409);
    }

    // ─── Histórico ──────────────────────────────────────────

    public function test_cadastro_com_telefone_de_outra_pessoa_nao_revela_historico_anterior(): void
    {
        // Visita antiga, anônima, com este telefone.
        $old = $this->enterTableAnonymously($this->table)->assertCreated();
        $client = User::find($old->json('user.id'));
        $this->orderFor($client, AppSession::find($old->json('session.id')));
        $this->closeSession($old->json('session.id'));

        $this->travel(1)->minutes();

        // Alguém cadastra o número: aproveita o cliente, mas não vê o passado.
        $token = $this->register()->assertCreated()->json('token');
        $this->assertSame($client->id, User::where('phone', self::PHONE)->sole()->id);

        $this->as($token)->getJson('/api/client/orders')->assertOk()->assertJsonCount(0, 'data');

        // Pedido feito já com conta aparece.
        $this->travel(1)->minutes();
        $link = $this->as($token)->postJson('/api/client/tables/link', ['table_token' => $this->table->qr_code_token]);
        $this->orderFor($client, AppSession::find($link->json('session.id')));

        $this->as($token)->getJson('/api/client/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.items.0.name', 'Moqueca')
            ->assertJsonPath('data.0.total', 80);
    }

    public function test_conta_nao_ve_pedidos_de_outra_conta(): void
    {
        $tokenAna = $this->register()->json('token');
        $tokenBia = $this->register(['phone' => '84977776666', 'name' => 'Bia'])->json('token');

        $bia  = User::where('phone', '84977776666')->sole();
        $link = $this->as($tokenBia)->postJson('/api/client/tables/link', ['table_token' => $this->table2->qr_code_token]);
        $this->orderFor($bia, AppSession::find($link->json('session.id')));

        $this->as($tokenAna)->getJson('/api/client/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->as($tokenBia)->getJson('/api/client/orders')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_cliente_anonimo_cria_conta_na_mesa_e_mantem_pedidos_da_visita(): void
    {
        // Visita anterior (não deve aparecer).
        $old = $this->enterTableAnonymously($this->table)->assertCreated();
        $client = User::find($old->json('user.id'));
        $this->orderFor($client, AppSession::find($old->json('session.id')));
        $this->closeSession($old->json('session.id'));

        $this->travel(1)->minutes();

        // Visita atual, ainda anônimo, com um pedido.
        $now = $this->enterTableAnonymously($this->table)->assertCreated();
        $this->orderFor($client, AppSession::find($now->json('session.id')));
        $tableToken = $now->json('token');

        // Sem conta, o token da mesa não abre o histórico.
        $this->as($tableToken)->getJson('/api/client/orders')->assertForbidden();

        $account = $this->as($tableToken)->postJson('/api/client/account', [
            'password' => self::PASSWORD, 'accept_terms' => true,
        ])->assertCreated();

        $this->as($account->json('token'))->getJson('/api/client/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ─── Perfil, exclusão e gerente ─────────────────────────

    public function test_troca_de_senha_exige_a_senha_atual(): void
    {
        $token = $this->register()->json('token');

        $this->as($token)->putJson('/api/client/me', ['current_password' => 'errada-000', 'password' => 'nova-senha-456'])
            ->assertUnprocessable();
        $this->as($token)->putJson('/api/client/me', ['current_password' => self::PASSWORD, 'password' => 'nova-senha-456'])
            ->assertOk();

        $this->postJson('/api/client/login', ['phone' => self::PHONE, 'password' => 'nova-senha-456'])->assertOk();
    }

    public function test_exclusao_da_conta_anonimiza_e_desconecta(): void
    {
        $token = $this->register()->json('token');
        $link  = $this->as($token)->postJson('/api/client/tables/link', ['table_token' => $this->table->qr_code_token]);

        $this->as($token)->deleteJson('/api/client/me', ['password' => self::PASSWORD])->assertStatus(409);

        $this->closeSession($link->json('session.id'));
        $this->as($token)->deleteJson('/api/client/me', ['password' => 'errada-000'])->assertUnprocessable();
        $this->as($token)->deleteJson('/api/client/me', ['password' => self::PASSWORD])->assertOk();

        $user = User::find($link->json('user.id'));
        $this->assertNull($user->phone);
        $this->assertNull($user->password);
        $this->assertSame('Cliente', $user->name);
        $this->assertNull(AppSession::find($link->json('session.id'))->device_id);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_gerente_redefine_acesso_do_cliente(): void
    {
        $this->register()->assertCreated();
        $client = User::where('phone', self::PHONE)->sole();

        $managerB = User::factory()->forRestaurant($this->otherRestaurant)->manager()->create();
        $this->actingAs($managerB)->postJson("/api/admin/clients/{$client->id}/reset-account")->assertForbidden();

        $manager = User::factory()->forRestaurant($this->restaurant)->manager()->create();
        $this->app['auth']->forgetGuards();
        $this->actingAs($manager)->getJson('/api/admin/clients?only_accounts=1')
            ->assertOk()
            ->assertJsonPath('data.0.phone', self::PHONE)
            ->assertJsonPath('data.0.has_account', true);
        $this->actingAs($manager)->postJson("/api/admin/clients/{$client->id}/reset-account")->assertOk();
        $this->actingAs($manager)->postJson("/api/admin/clients/{$manager->id}/reset-account")->assertForbidden();

        $this->postJson('/api/client/login', ['phone' => self::PHONE, 'password' => self::PASSWORD])->assertUnauthorized();
        $this->assertSame(0, $client->tokens()->count());

        // O cliente pode se cadastrar de novo com o mesmo telefone.
        $this->register(['password' => 'nova-senha-456'])->assertCreated();
    }
}
