# Deploy — Pacote A — App Caixa (versão com patches aplicados)

Esta pasta `api-patched/` contém os 5 arquivos com **patches já aplicados**, prontos
para substituir os atuais no servidor. Use esta entrega em vez do `PATCHES.md` manual.

## Mapa de arquivos → destino no VPS

### Arquivos novos (copiar para o VPS)
| Origem | Destino |
|---|---|
| `api/database/migrations/2026_05_06_000001_add_cashier_support.php` | `/var/www/checkfood/api/database/migrations/2026_05_06_000001_add_cashier_support.php` |
| `api/app/Http/Controllers/Caixa/CaixaController.php` | `/var/www/checkfood/api/app/Http/Controllers/Caixa/CaixaController.php` |

### Arquivos substituídos (sobrescrever)
| Origem (api-patched/) | Destino |
|---|---|
| `app/Enums/UserRole.php` | `/var/www/checkfood/api/app/Enums/UserRole.php` |
| `app/Enums/SessionStatus.php` | `/var/www/checkfood/api/app/Enums/SessionStatus.php` |
| `app/Models/AppSession.php` | `/var/www/checkfood/api/app/Models/AppSession.php` |
| `app/Models/Restaurant.php` | `/var/www/checkfood/api/app/Models/Restaurant.php` |
| `app/Http/Controllers/WaiterSessionController.php` | `/var/www/checkfood/api/app/Http/Controllers/WaiterSessionController.php` |
| `app/Http/Controllers/RestaurantSettingsController.php` | `/var/www/checkfood/api/app/Http/Controllers/RestaurantSettingsController.php` |
| `app/Http/Middleware/CheckRole.php` | `/var/www/checkfood/api/app/Http/Middleware/CheckRole.php` ⚠️ ver nota |

### ⚠️ Nota sobre o middleware

O arquivo `CheckRole.php` que entreguei assume o nome **CheckRole** que é o padrão
Laravel 11. Antes de substituir, **confirme o nome real** do middleware no servidor:

```bash
ls /var/www/checkfood/api/app/Http/Middleware/
grep -rn "'role'" /var/www/checkfood/api/bootstrap/app.php
```

Cenários possíveis:
- Arquivo é `CheckRole.php` e alias é `role` → substitua diretamente
- Arquivo é `CheckUserRole.php` ou outro nome → renomeie minha versão antes de subir
- Não existe ainda → suba o arquivo e adicione o alias em `bootstrap/app.php`:
  ```php
  ->withMiddleware(function (Middleware $middleware) {
      $middleware->alias([
          'role' => \App\Http\Middleware\CheckRole::class,
      ]);
  })
  ```

---

## Adicionar rotas em `api.php` (manualmente)

Esta é a única edição manual restante. Em `/var/www/checkfood/api/routes/api.php`:

### 1. Adicione o import no topo

Junto com os outros `use App\Http\Controllers\...`:

```php
use App\Http\Controllers\Caixa\CaixaController;
```

### 2. Adicione a rota do garçom

Junto com as outras rotas de waiter (sessions/orders), dentro do middleware `auth:sanctum`:

```php
Route::post('/waiter/sessions/{session}/forward-cashier',
    [WaiterSessionController::class, 'forwardToCashier']);
```

### 3. Adicione o bloco do caixa

Em qualquer lugar dentro do middleware `auth:sanctum`:

```php
// ── App Caixa ──
Route::middleware('role:cashier,manager')->prefix('caixa')->group(function () {
    Route::get('/queue',                                  [CaixaController::class, 'queue']);
    Route::get('/sessions/{session}',                     [CaixaController::class, 'show']);
    Route::post('/sessions/{session}/confirm-payment',    [CaixaController::class, 'confirmPayment']);
});
```

### 4. Endpoint de update de settings (opcional, se ainda não existir)

Eu adicionei um método `update()` em `RestaurantSettingsController`. Se a rota ainda
não existe no `api.php`, registre dentro do bloco `admin`:

```php
Route::middleware('role:manager')->prefix('admin')->group(function () {
    // ... rotas existentes ...
    Route::patch('/restaurant/settings', [RestaurantSettingsController::class, 'update']);
});
```

---

## Sequência de deploy

```bash
cd /var/www/checkfood/api

# 1. Backup defensivo (boa prática antes de patches)
cp app/Models/AppSession.php{,.bak-$(date +%s)}
cp app/Models/Restaurant.php{,.bak-$(date +%s)}
cp app/Http/Controllers/WaiterSessionController.php{,.bak-$(date +%s)}
cp app/Http/Controllers/RestaurantSettingsController.php{,.bak-$(date +%s)}

# 2. Copie os arquivos do output para o VPS

# 3. Rodar migration
php artisan migrate

# 4. Limpar caches
php artisan optimize:clear
sudo systemctl reload php8.3-fpm

# 5. Validar rotas
php artisan route:list | grep -E "caixa|forward-cashier"
```

Esperado:
```
POST   api/waiter/sessions/{session}/forward-cashier  ... WaiterSessionController@forwardToCashier
GET    api/caixa/queue                                ... CaixaController@queue
GET    api/caixa/sessions/{session}                   ... CaixaController@show
POST   api/caixa/sessions/{session}/confirm-payment   ... CaixaController@confirmPayment
```

---

## Validação manual

### Habilitar cashier_mode num restaurante de teste

```bash
mysql -u checkfood_user -p checkfood_db -e "
UPDATE restaurants SET cashier_mode = 1 WHERE id = 1;
"
```

### Criar usuário caixa de teste

```bash
php artisan tinker --execute="
\App\Models\User::create([
    'restaurant_id' => 1,
    'name'     => 'Caixa Teste',
    'apelido'  => 'caixa-teste',
    'phone'    => '11999999999',
    'password' => bcrypt('senha123'),
    'role'     => 'cashier',
    'is_active'=> true,
]);
"
```

### Testar fluxo completo via curl

```bash
# 1. Login do garçom (substitua credenciais)
WAITER_TOKEN=$(curl -s -X POST https://pagfood.online/api/auth/login \
  -d 'login=marcos&password=...' | jq -r '.token')

# 2. Encaminhar uma sessão ativa ao caixa
curl -X POST https://pagfood.online/api/waiter/sessions/123/forward-cashier \
  -H "Authorization: Bearer $WAITER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"payment_method": "pix"}'

# 3. Login do caixa
CASHIER_TOKEN=$(curl -s -X POST https://pagfood.online/api/auth/login \
  -d 'login=caixa-teste&password=senha123' | jq -r '.token')

# 4. Ver fila do caixa
curl -H "Authorization: Bearer $CASHIER_TOKEN" \
     https://pagfood.online/api/caixa/queue

# 5. Detalhes da sessão
curl -H "Authorization: Bearer $CASHIER_TOKEN" \
     https://pagfood.online/api/caixa/sessions/123

# 6. Confirmar pagamento total
curl -X POST https://pagfood.online/api/caixa/sessions/123/confirm-payment \
  -H "Authorization: Bearer $CASHIER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"amount": 184.50, "method": "pix"}'
```

---

## Rollback (se necessário)

```bash
cd /var/www/checkfood/api

# Restaurar arquivos do backup
cp app/Models/AppSession.php.bak-* app/Models/AppSession.php
cp app/Models/Restaurant.php.bak-* app/Models/Restaurant.php
# ... etc

# Reverter migration
php artisan migrate:rollback --step=1

php artisan optimize:clear
sudo systemctl reload php8.3-fpm
```
