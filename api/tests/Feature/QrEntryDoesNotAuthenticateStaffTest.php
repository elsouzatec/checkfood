<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A entrada pelo QR da mesa (POST /sessions) identifica o cliente só pelo
 * telefone. Ela nunca pode autenticar um funcionário: quem digita o telefone
 * do gerente na mesa não pode sair com um token de gerente.
 */
class QrEntryDoesNotAuthenticateStaffTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restaurant;

    private Table $table;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurant = Restaurant::factory()->create();
        $this->table = Table::create([
            'restaurant_id' => $this->restaurant->id,
            'number'        => 7,
            'qr_code_token' => (string) Str::uuid(),
        ]);
        $this->manager = User::factory()->forRestaurant($this->restaurant)->manager()->create([
            'phone' => '84999990000',
        ]);
    }

    private function enterTable(string $phone)
    {
        return $this->postJson('/api/sessions', [
            'table_token' => $this->table->qr_code_token,
            'phone'       => $phone,
        ]);
    }

    public function test_telefone_do_gerente_na_mesa_nao_gera_token_de_gerente(): void
    {
        $response = $this->enterTable('(84) 99999-0000')->assertCreated();

        $this->assertNotSame($this->manager->id, $response->json('user.id'));
        $this->assertSame('client', $response->json('user.role'));
        $this->assertSame(0, $this->manager->tokens()->count());

        // O token emitido não abre a área administrativa.
        $this->withToken($response->json('token'))
            ->getJson('/api/admin/users')
            ->assertForbidden();
    }

    public function test_funcionario_pode_ser_cliente_da_propria_casa_com_o_mesmo_telefone(): void
    {
        $this->enterTable('84999990000')->assertCreated();

        $client = User::where('role', UserRole::CLIENT)->where('phone', '84999990000')->first();
        $this->assertNotNull($client);
        $this->assertSame($this->restaurant->id, $client->restaurant_id);
        $this->assertSame(UserRole::MANAGER, $this->manager->fresh()->role);
    }
}
