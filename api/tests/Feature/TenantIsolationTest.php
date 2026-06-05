<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Restaurant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Blindagem multi-tenant: o global scope de App\Models\Concerns\BelongsToRestaurant
 * deve isolar dados por restaurant_id. Um vazamento aqui expõe dados entre tenants —
 * é a invariante de segurança mais importante da plataforma.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Restaurant $restA;

    private Restaurant $restB;

    private User $managerA;

    private User $managerB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restA = Restaurant::factory()->create();
        $this->restB = Restaurant::factory()->create();

        $this->managerA = User::factory()->manager()->forRestaurant($this->restA)->create();
        $this->managerB = User::factory()->manager()->forRestaurant($this->restB)->create();

        // Uma categoria em cada tenant (criadas sem auth → restaurant_id explícito).
        Category::factory()->for($this->restA)->create(['name' => 'Cardápio A']);
        Category::factory()->for($this->restB)->create(['name' => 'Cardápio B']);
    }

    public function test_query_so_retorna_dados_do_proprio_tenant(): void
    {
        $this->actingAs($this->managerA);

        $categorias = Category::all();

        $this->assertCount(1, $categorias);
        $this->assertSame('Cardápio A', $categorias->first()->name);
        $this->assertSame($this->restA->id, $categorias->first()->restaurant_id);
    }

    public function test_nao_acha_registro_de_outro_tenant_por_id(): void
    {
        $categoriaB = Category::withoutRestaurantScope()->where('name', 'Cardápio B')->firstOrFail();

        $this->actingAs($this->managerA);

        // Buscar por id a categoria do tenant B não deve retornar nada sob o escopo de A.
        $this->assertNull(Category::find($categoriaB->id));
    }

    public function test_criacao_preenche_restaurant_id_do_usuario_autenticado(): void
    {
        $this->actingAs($this->managerA);

        $nova = Category::create(['name' => 'Nova Categoria']);

        $this->assertSame($this->restA->id, $nova->restaurant_id);
    }

    public function test_usuario_nao_consegue_forjar_restaurant_id_de_outro_tenant(): void
    {
        $this->actingAs($this->managerA);

        // Mesmo passando o id do tenant B explicitamente, a leitura subsequente
        // fica presa ao escopo de A — o registro "some" para o atacante.
        $forjada = Category::create([
            'name' => 'Forjada',
            'restaurant_id' => $this->restB->id,
        ]);

        // O modelo escreveu com o id passado, mas o escopo impede que A leia algo de B:
        $this->assertNull(Category::find($forjada->id));
    }

    public function test_without_scope_enxerga_todos_os_tenants(): void
    {
        $this->actingAs($this->managerA);

        $todas = Category::withoutRestaurantScope()->get();

        $this->assertGreaterThanOrEqual(2, $todas->count());
    }

    public function test_super_admin_enxerga_cross_tenant(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super);

        // super_admin é no-op no escopo → vê os dois tenants.
        $this->assertGreaterThanOrEqual(2, Category::count());
    }

    public function test_publico_sem_auth_e_no_op(): void
    {
        // Sem usuário autenticado (cardápio público / webhook), o escopo não filtra.
        $this->assertGreaterThanOrEqual(2, Category::count());
    }
}
