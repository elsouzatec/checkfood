# CheckFood / PagFood

Plataforma SaaS **multi-tenant** para restaurantes: cardápio digital por QR code, pedidos, cozinha, caixa, garçom e administração. Uma instância serve vários restaurantes, isolados por `restaurant_id`. "PagFood" é o nome da plataforma; cada restaurante tem nome/logo próprios.

## Estrutura do monorepo

```
/var/www/checkfood
├── api/                 Backend — Laravel 13 + Sanctum (API REST)
├── frontend/
│   ├── apps/            5 apps de loja + 1 da plataforma (Vue 3 + Vite)
│   │   ├── cliente/     cardápio/pedido do cliente (QR da mesa)
│   │   ├── garcom/      operação do garçom
│   │   ├── cozinha/     painel da cozinha (KDS)
│   │   ├── caixa/       fechamento de conta / pagamentos
│   │   ├── admin/       gestão do restaurante (gerente)
│   │   └── plataforma/  super admin (gestão de tenants + mensalidades)
│   ├── shared/          CSS compartilhado (design tokens)
│   └── site/            vitrine de produto estática (https://pagfood.online/produto)
└── Docs/                ARQUITETURA.md, API.md, deploy
```

## Stack

- **Backend:** PHP 8.3, Laravel 13, Sanctum (auth por token Bearer), MariaDB, Redis (cache/queue/session).
- **Frontend:** Vue 3 (`<script setup>`), Vite, Pinia, Vue Router, Axios. Cada app é um projeto Vite independente com seu `base` e porta.
- **Pagamentos:** Mercado Pago (PIX/cartão) por restaurante; webhook em `/api/webhooks/mercadopago`.

## Comandos

```bash
# Backend (em api/)
php artisan migrate              # migrations
php artisan db:seed              # popula demo + super admin
php artisan route:list           # inspeciona rotas
php artisan tinker               # REPL
php artisan billing:check-overdue # suspende restaurantes inadimplentes (agendado 03:00)
vendor/bin/pint                  # formatação (Laravel Pint)

# Frontend (em frontend/apps/<app>/)
npm install
npm run dev                      # dev server
npm run build                    # build de produção (dist/)
```

Não há suíte de testes (apenas stubs `ExampleTest`). Valide mudanças de API com `route:list` + tinker.

## Arquitetura — pontos essenciais

- **Papéis** (`App\Enums\UserRole`): `client`, `waiter`, `kitchen`, `cashier`, `manager` (admin do restaurante) e `super_admin` (plataforma, sem `restaurant_id`).
- **Multi-tenancy:** o trait `App\Models\Concerns\BelongsToRestaurant` aplica um **global scope** que filtra por `restaurant_id` do usuário autenticado e auto-preenche o campo na criação. É **no-op** sem autenticação (cardápio público, webhook) e para `super_admin`. Use `Model::withoutRestaurantScope()` para queries cross-tenant. **Não** aplique o trait ao `User` (recursão na auth).
- **Autenticação:** token Bearer (Sanctum). Login em `POST /api/auth/login`: `phone` (único entre staff na plataforma — **dispensa** código de restaurante), `apelido`+`restaurant_slug` (apelido só é único por restaurante), ou `email` (global — super admin). Ver `LoginController`.
- **Rotas:** `routes/api.php`. **Regra de ouro:** rotas de nome fixo ANTES de wildcards `{param}`. Grupos por papel via middleware `role:` (`EnsureRole`): `/api/admin` (manager), `/api/caixa` (cashier,manager), `/api/platform` (super_admin).
- **Máquina de estados de pedido:** `App\Enums\OrderStatus` com `allowedTransitions()`/`canTransitionTo()`. Use `Order::transitionTo()`.
- **Sessões de mesa:** `App\Enums\SessionStatus` (`active`/`closing`/`at_cashier`/`closed`).

## Camada de plataforma (super admin)

- Controllers em `app/Http/Controllers/Platform/`. Endpoints sob `/api/platform` (role `super_admin`).
- `PlatformRestaurantController`: cria restaurante + gerente inicial, edita, suspende/reativa, configura **modo de deploy** (`deployment_mode`: `domain`/`intranet`) e `frontend_url` por tenant.
- **`frontend_url` por restaurante** alimenta `Restaurant::effectiveFrontendUrl()`, usada em `buildQrUrl()` — em intranet o QR aponta para o IP local. Fallback: `config('app.frontend_url')` → `FRONTEND_URL` no `.env`.
- **Mensalidades:** `PlatformBillingController` + models `Subscription`/`SubscriptionPayment`. Controle manual; registrar pagamento estende `paid_until` e reativa. O comando `billing:check-overdue` suspende inadimplentes (`is_active=false`).

## Convenções

- Escopar SEMPRE por `restaurant_id` em endpoints de tenant (o trait ajuda, mas valide). Endpoints de plataforma são cross-tenant.
- Credenciais de gateway (`mercadopago_*`) são `encrypted` e `hidden` no model `Restaurant`.
- Frontend: `baseURL` da API vem de `VITE_API_URL` (`.env` por app). Token guardado em `localStorage` com chave por app (`admin_token`, `platform_token`, etc.).

Detalhes em [ARQUITETURA.md](ARQUITETURA.md), [API.md](API.md) e [DEPLOY.md](DEPLOY.md).

> **App plataforma (super admin)** roda numa porta dedicada por IP, sem domínio: **https://72.61.46.146:8088/** (cert self-signed). Build/deploy e cron em [DEPLOY.md](DEPLOY.md).
