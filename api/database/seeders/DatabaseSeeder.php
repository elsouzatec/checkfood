<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Super admin da plataforma (sem restaurante; login por e-mail) ──
        User::firstOrCreate(
            ['email' => 'super@pagfood.online'],
            [
                'restaurant_id' => null,
                'name'          => 'Super Admin',
                'password'      => Hash::make('super@1234'),
                'role'          => 'super_admin',
                'is_active'     => true,
            ]
        );

        // ── Restaurante ──
        $restaurant = Restaurant::create([
            'name' => 'PagFood Demo',
            'slug' => 'pagfood-demo',
            'address' => 'Av. Presidente Vargas, 123 - Belém/PA',
            'phone' => '(91) 3333-0000',
        ]);

        // ── Staff ──
        User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Gerente',
            'phone' => '91999990001',
            'email' => 'gerente@pagfood.online',
            'password' => Hash::make('admin123'),
            'role' => 'manager',
        ]);

        User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Garçom',
            'phone' => '91999990002',
            'email' => 'garcom@pagfood.online',
            'password' => Hash::make('garcom123'),
            'role' => 'waiter',
        ]);

        User::create([
            'restaurant_id' => $restaurant->id,
            'name' => 'Cozinha',
            'phone' => '91999990003',
            'email' => 'cozinha@pagfood.online',
            'password' => Hash::make('cozinha123'),
            'role' => 'kitchen',
        ]);

        // ── Mesas (15) ──
        for ($i = 1; $i <= 15; $i++) {
            Table::create([
                'restaurant_id' => $restaurant->id,
                'number' => $i,
                'capacity' => $i <= 10 ? 4 : 6,
                'qr_code_token' => Str::uuid()->toString(),
            ]);
        }

        // ── Categorias e Produtos ──
        // Formato: 'Nome Categoria' => ['area' => 'setor', 'products' => [...]]
        $cardapio = [
            'Entradas' => [
                'area' => 'lanchonete',
                'products' => [
                    ['name' => 'Bolinho de Bacalhau (6un)', 'price' => 28.90, 'description' => 'Crocantes por fora, cremosos por dentro'],
                    ['name' => 'Mandioca Frita', 'price' => 19.90, 'description' => 'Porção generosa com molho especial'],
                    ['name' => 'Bruschetta Tomate e Manjericão', 'price' => 24.90, 'description' => 'Pão italiano, tomate fresco e azeite'],
                ],
            ],
            'Pratos Principais' => [
                'area' => 'cozinha',
                'products' => [
                    ['name' => 'Filé Grelhado com Fritas', 'price' => 59.90, 'description' => 'Filé mignon 250g, fritas crocantes e salada'],
                    ['name' => 'Salmão ao Molho de Maracujá', 'price' => 69.90, 'description' => 'Salmão 200g, arroz e legumes grelhados'],
                    ['name' => 'Risoto de Camarão', 'price' => 64.90, 'description' => 'Arroz arbóreo, camarões frescos e parmesão'],
                    ['name' => 'Frango à Parmegiana', 'price' => 45.90, 'description' => 'Filé empanado, molho de tomate e queijo gratinado'],
                    ['name' => 'Picanha na Chapa (400g)', 'price' => 79.90, 'description' => 'Acompanha arroz, farofa, vinagrete e fritas'],
                ],
            ],
            'Massas' => [
                'area' => 'cozinha',
                'products' => [
                    ['name' => 'Spaghetti Carbonara', 'price' => 42.90, 'description' => 'Massa fresca, bacon, ovo e parmesão'],
                    ['name' => 'Lasanha Bolonhesa', 'price' => 39.90, 'description' => 'Camadas de massa, carne moída e queijo'],
                    ['name' => 'Fettuccine Alfredo', 'price' => 38.90, 'description' => 'Creme de leite fresco e parmesão'],
                ],
            ],
            'Bebidas' => [
                'area' => 'bar',
                'products' => [
                    ['name' => 'Coca-Cola Lata', 'price' => 7.90, 'description' => '350ml'],
                    ['name' => 'Suco Natural de Laranja', 'price' => 12.90, 'description' => '400ml, sem açúcar'],
                    ['name' => 'Água Mineral', 'price' => 5.90, 'description' => '500ml com ou sem gás'],
                    ['name' => 'Cerveja Artesanal IPA', 'price' => 22.90, 'description' => 'Long neck 355ml'],
                    ['name' => 'Caipirinha de Limão', 'price' => 18.90, 'description' => 'Cachaça, limão e açúcar'],
                ],
            ],
            'Sobremesas' => [
                'area' => 'cozinha',
                'products' => [
                    ['name' => 'Petit Gâteau', 'price' => 29.90, 'description' => 'Bolo de chocolate com centro derretido e sorvete'],
                    ['name' => 'Pudim de Leite', 'price' => 16.90, 'description' => 'Receita tradicional com calda de caramelo'],
                    ['name' => 'Açaí na Tigela (300ml)', 'price' => 22.90, 'description' => 'Granola, banana e leite condensado'],
                ],
            ],
            'Porções' => [
                'area' => 'lanchonete',
                'products' => [
                    ['name' => 'Batata Frita Grande', 'price' => 29.90, 'description' => 'Porção para compartilhar com cheddar e bacon'],
                    ['name' => 'Isca de Frango', 'price' => 34.90, 'description' => 'Empanado crocante com molho barbecue'],
                    ['name' => 'Anéis de Cebola', 'price' => 24.90, 'description' => 'Porção com molho especial da casa'],
                ],
            ],
            'Combos' => [
                'area' => 'cozinha',
                'products' => [
                    ['name' => 'Combo Executivo', 'price' => 35.90, 'description' => 'Prato do dia + bebida + sobremesa'],
                    ['name' => 'Combo Casal', 'price' => 119.90, 'description' => '2 pratos principais + 1 entrada + 2 bebidas'],
                ],
            ],
        ];

        $sortCategory = 1;
        foreach ($cardapio as $categoryName => $categoryData) {
            $category = Category::create([
                'restaurant_id' => $restaurant->id,
                'name' => $categoryName,
                'preparation_area' => $categoryData['area'],
                'sort_order' => $sortCategory++,
            ]);

            $sortProduct = 1;
            foreach ($categoryData['products'] as $product) {
                Product::create([
                    'restaurant_id' => $restaurant->id,
                    'category_id' => $category->id,
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'price' => $product['price'],
                    'sort_order' => $sortProduct++,
                ]);
            }
        }
    }
}
