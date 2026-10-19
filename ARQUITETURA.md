# Arquitetura — CheckFood / PagFood

## Visão geral

Plataforma SaaS multi-tenant para restaurantes. Uma única instância (API + bancos) atende vários restaurantes, isolados logicamente por `restaurant_id`. Cada restaurante opera com até 5 apps de loja; a plataforma é gerida por um super admin.

```
                        ┌──────────────────────────┐
   Clientes (QR)  ────► │                          │
   Garçom         ────► │   API Laravel (Sanctum)  │ ──► MariaDB
   Cozinha        ────► │   /api/*                 │ ──► Redis (cache/queue/session)
   Caixa          ────► │                          │ ──► Mercado Pago (PIX/cartão)
   Admin/Gerente  ────► │                          │
   Super Admin    ────► │                          │
                        └──────────────────────────┘
```

## Papéis (`App\Enums\UserRole`)

| Papel | `restaurant_id` | App | Escopo |
|---|---|---|---|
| `client` | sim | cliente | sessão da própria mesa; com senha = **conta** naquele restaurante |
| `waiter` | sim | garçom | restaurante |
| `kitchen` | sim | cozinha | restaurante |
| `cashier` | sim | caixa | restaurante |
| `manager` | sim | admin | restaurante (admin do tenant) |
| `super_admin` | **null** | plataforma | cross-tenant |

## Multi-tenancy

Isolamento por `restaurant_id` em todas as tabelas de domínio. Reforçado pelo trait **`App\Models\Concerns\BelongsToRestaurant`**:

- **Global scope** que filtra automaticamente por `restaurant_id` do usuário autenticado.
- **Auto-fill** de `restaurant_id` ao criar registros.
- **No-op** quando: não há usuário autenticado (cardápio público, webhook, login) ou o usuário é `super_admin`.
- Escapar com `Model::withoutRestaurantScope()` (usado nos controllers de plataforma).
- Aplicado a: `AppSession`, `CashierShift`, `Category`, `Feedback`, `Ingredient`, `Order`, `Payment`, `PrintJob`, `Product`, `Table`, `WaiterCall`.
- **NÃO** aplicado a `User` — a resolução do usuário pelo Sanctum consulta `users` e causaria recursão. Tenancy de `User` é tratada explicitamente (login e `AdminUserController`).

### Decisões (contexto)
- Modelo multi-tenant (não 1-VPS-por-restaurante nem DB-per-tenant). Perfil: 5–10 clientes pequenos, solo dev.
- "Filiais" (branches) é a evolução prevista, ainda não implementada.

## Autenticação

- **Sanctum, token Bearer** (não há sessão por cookie). Token em `localStorage` no frontend, enviado em `Authorization: Bearer`.
- `POST /api/auth/login` aceita:
  - `phone` + `password` — **telefone é único entre staff em toda a plataforma**, então dispensa o código do restaurante (o tenant é derivado do usuário). Em colisão legada, a API responde 409 pedindo o `restaurant_slug` como desempate.
  - `apelido` + `password` + `restaurant_slug` (apelido só é único por restaurante → slug obrigatório)
  - `email` + `password` (global; super admin entra assim, sem restaurante)
- Unicidade global do telefone entre staff (`waiter`/`kitchen`/`cashier`/`manager`) é validada no cadastro (`AdminUserController`, `PlatformRestaurantController`). Restaurante inativo bloqueia login (exceto super admin).
- Habilidade especial `print-agent`: token Sanctum dedicado para o agente de impressão (`/api/print-agent/*`).

### Cliente: mesa e conta
- **Entrada pela mesa** (`POST /sessions`): QR + telefone, sem senha. Só encontra/cria usuários `role=client`
  — o telefone de um funcionário digitado na mesa vira um cliente separado, nunca o token do funcionário.
- **Conta do cliente — por restaurante** ([CONTA_CLIENTE.md](CONTA_CLIENTE.md)): o mesmo `users` `role=client`
  com senha (`registered_at`). Mesmo telefone em restaurantes diferentes = contas independentes. Base da
  futura carteira digital. Telefone com conta não entra mais na mesa só pelo número (`requires_login`).
- **Dois tokens no app cliente:** `pf_account_token` (conta, `client-account`, 90 dias) e `pf_token`
  (sessão da mesa, `client`, renovado a cada visita). `POST /client/tables/link` troca o primeiro pelo segundo.
- `App\Services\Session\TableSessionService` concentra a regra de ocupação da mesa para as duas entradas.
- Rotas de operação (garçom/cozinha) exigem papel além do restaurante — ver API.md.

## Máquina de estados

### Pedido (`OrderStatus`)
```
pendente_validacao ─► confirmado ─► preparando ─► pronto ─► entregue ─► pago
        └───────────────┴────────────┴───────────┴────────► cancelado
```
Transições válidas em `allowedTransitions()`; aplicar via `Order::transitionTo()` (preenche timestamps granulares). `forceCancel()` ignora a máquina (usado na liberação de mesa).

### Sessão de mesa (`SessionStatus`)
`active` → `closing` (pagamento online em andamento) ou `at_cashier` (garçom encaminhou ao caixa) → `closed`.

### Turno de caixa (`CashierShiftStatus`)
`open` → `closed`. Turno por operador (`cashier`), com abertura/fechamento e conferência de valores.

## Apps de frontend

Cada app é um projeto Vite independente (`frontend/apps/<app>/`), com `base` próprio, porta de dev e token em `localStorage`. CSS compartilhado em `frontend/shared/` (design tokens).

| App | `base` | Porta dev | Token | Acesso em produção | Público |
|---|---|---|---|---|---|
| cliente | `/` | — | `pf_token` | https://pagfood.online/ | cliente final (QR) |
| garcom | `/garcom/` | — | `garcom_token` | https://pagfood.online/garcom/ | garçom |
| cozinha | `/cozinha/` | — | `auth_token` | https://pagfood.online/cozinha/ | cozinha |
| caixa | `/caixa/` | — | `caixa_token` | https://pagfood.online/caixa/ | caixa |
| admin | `/admin/` | 5176 | `admin_token` | https://pagfood.online/admin/ | gerente |
| plataforma | `/` | 5177 | `platform_token` | **https://72.61.46.146:8088/** (IP, sem domínio, cert self-signed) | super admin |

O app **plataforma** é servido num `server` block dedicado na porta 8088 (ver [DEPLOY.md](DEPLOY.md)), desacoplado do domínio. Por isso seu `base` é `/` (e não `/plataforma/`).

## Camada de plataforma (super admin)

Controllers em `app/Http/Controllers/Platform/`, sob `/api/platform` (middleware `role:super_admin`).

### Provisionamento (`PlatformRestaurantController`)
- Lista/cria/edita restaurantes; cria o **gerente inicial** junto na criação (transação).
- Suspende/reativa (`is_active`).
- Configura **modo de deploy** e **URL do frontend** por tenant.

### Domínio vs. intranet
- `restaurants.deployment_mode` (`App\Enums\DeploymentMode`): `domain` ou `intranet`.
- `restaurants.frontend_url`: URL base canônica do tenant (ex.: `https://x.com` ou `http://192.168.0.50:8080`).
- `Restaurant::effectiveFrontendUrl()` resolve: URL própria → fallback `config('app.frontend_url')` (← `FRONTEND_URL` no `.env`) → `APP_URL`.
- `AdminTableController::buildQrUrl()` usa essa URL: em intranet, o QR da mesa aponta para o IP local.
- **Pagamento online (Mercado Pago) exige internet** — em intranet pura, usar o fluxo de confirmação no caixa.

### Mensalidades (controle manual + auto-suspensão)
- `subscriptions` (1 por restaurante): `monthly_amount`, `billing_day`, `paid_until`, `status` (`App\Enums\SubscriptionStatus`: `trial`/`active`/`suspended`).
- `subscription_payments`: histórico de pagamentos manuais (`amount`, `paid_at`, período, `method`, `recorded_by`).
- `PlatformBillingController`: configurar valor/vencimento, registrar pagamento (estende `paid_until` em 1 mês e reativa o restaurante), consultar histórico.
- **`billing:check-overdue`** (agendado diário 03:00 em `routes/console.php`): suspende restaurantes com `paid_until` vencido (`is_active=false`). **Requer o cron do SO** rodando `php artisan schedule:run` a cada minuto.

## Modelo de dados (principais tabelas)

`restaurants` (tenant) · `users` (papéis) · `tables` (+ `qr_code_token`) · `categories` · `products` · `ingredients` + `product_ingredients` (ficha técnica) · `sessions_app` (sessão de mesa) · `orders` + `order_items` · `payments` · `cashier_shifts` · `waiter_calls` · `feedbacks` · `print_jobs` · `subscriptions` + `subscription_payments`.

## Observações de operação

- `APP_ENV` deve ser `production` no servidor de produção (verificar — já foi visto como `local`).
- Sem `config/cors.php` → default `*`. Restringir origens se necessário.
- Agendador depende do cron do SO (ver mensalidades).
