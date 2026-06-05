# API — CheckFood / PagFood

Base: `/api` · Auth: Sanctum (header `Authorization: Bearer <token>`) · Respostas JSON.
Fonte: `api/routes/api.php`.

## Autenticação

| Método | Rota | Descrição |
|---|---|---|
| POST | `/auth/login` | Login. Body: `phone` (único entre staff — **sem** código) **ou** `apelido`+`restaurant_slug` **ou** `email` (global), sempre com `password`. Retorna `token`, `user`, `restaurant` (null p/ super admin). Em colisão legada de telefone retorna 409 com `requires_restaurant_slug`. Throttle `login`. |
| POST | `/auth/logout` | Revoga o token atual. (auth) |

## Públicas (sem auth)

| Método | Rota | Descrição |
|---|---|---|
| POST | `/sessions` | Abre sessão de mesa (cliente via QR). |
| GET | `/restaurants/by-slug/{slug}` | Resolve restaurante pelo slug (usado no login). |
| GET | `/restaurants/{restaurantId}/menu` | Cardápio público. |
| POST | `/webhooks/mercadopago` | Webhook de pagamento. Throttle `webhook`. |

## Autenticadas (todos os papéis) — `auth:sanctum`

### Sessões
- `GET /sessions/check` · `POST /sessions/{session}/close`

### Pagamentos
- `GET /payments/{payment}` · `POST /payments/{payment}/pay` · `POST /payments/{payment}/deep-link` · `POST /payments/{payment}/confirm-pos`

### Chamadas de garçom
- `GET /waiter-calls/status` · `GET /waiter-calls/pending` · `POST /waiter-calls` · `PATCH /waiter-calls/{waiterCall}/dismiss`

### Pedidos
- `GET /orders/my-session` · `GET /orders/pending` · `GET /orders/kitchen` · `GET /orders/ready`
- `GET /orders/{order}` · `POST /orders`
- `PATCH /orders/{order}/validate` · `/start` · `/ready` · `/deliver` · `/quick-deliver` · `/pick-and-deliver` · `/cancel`
- `DELETE /orders/{order}/items/{orderItem}`

### Garçom (sessões de guest)
- `POST /waiter/sessions` · `POST /waiter/sessions/{session}/orders` · `POST /waiter/sessions/{session}/forward-cashier` · `GET /waiter/menu`

### Mesas
- `GET /tables` · `GET /tables/{table}/orders`

### Configurações / Feedback
- `GET /restaurant/settings` (staff) · `GET /feedbacks/check/{sessionId}` · `POST /feedbacks`

## Caixa — `role:cashier,manager`, prefixo `/caixa`

- `GET /caixa/queue` · `GET /caixa/sessions/{session}` · `POST /caixa/sessions/{session}/confirm-payment`
- `POST /caixa/sessions/{session}/pix` · `POST /caixa/sessions/{session}/pix/cancel`
- `GET /caixa/history` · `GET /caixa/summary`
- Turno: `GET /caixa/shift` · `POST /caixa/shift/open` · `POST /caixa/shift/close`

## Plataforma — `role:super_admin`, prefixo `/platform`

### Restaurantes (`PlatformRestaurantController`)
| Método | Rota | Descrição |
|---|---|---|
| GET | `/platform/restaurants` | Lista todos (+ contagem de usuários e resumo de mensalidade). |
| POST | `/platform/restaurants` | Cria restaurante + gerente inicial. Body: `name`, `slug`, `address?`, `phone?`, `deployment_mode?` (`domain`/`intranet`), `frontend_url?`, `manager_name`, `manager_phone`, `manager_email?`, `manager_password`. |
| GET | `/platform/restaurants/{restaurant}` | Detalhe + gerentes. |
| PUT | `/platform/restaurants/{restaurant}` | Atualiza dados/deploy. |
| PATCH | `/platform/restaurants/{restaurant}/toggle-active` | Suspende/reativa. |
| POST | `/platform/restaurants/{restaurant}/managers` | Cria gerente adicional. |

### Mensalidades (`PlatformBillingController`)
| Método | Rota | Descrição |
|---|---|---|
| GET | `/platform/restaurants/{restaurant}/billing` | Assinatura + histórico de pagamentos. |
| PUT | `/platform/restaurants/{restaurant}/billing` | Configura `monthly_amount`, `billing_day?` (1–28), `notes?`. |
| POST | `/platform/restaurants/{restaurant}/billing/pay` | Registra pagamento (`amount?`, `paid_at?`, `method?`, `note?`). Estende `paid_until` +1 mês e reativa o restaurante. |

## Admin — `role:manager`, prefixo `/admin`

- **Restaurante:** `GET/PUT /admin/restaurant` · `PUT /admin/restaurant/payment-gateway` · `POST/DELETE /admin/restaurant/logo` · `PATCH /admin/restaurant/settings`
- **Categorias:** `apiResource /admin/categories`
- **Estoque:** `GET /admin/ingredients/alerts` · `apiResource /admin/ingredients` (index/store/update/destroy)
- **Ficha técnica:** `GET|POST /admin/products/{product}/ingredients` · `DELETE /admin/products/{product}/ingredients/{ingredient}`
- **Produtos:** `POST /admin/products/{product}/image` · `apiResource /admin/products`
- **Mesas:** `GET /admin/tables/{table}/detail` · `POST /admin/tables/{table}/force-release` · `POST /admin/tables/bulk` · `GET /admin/tables/{table}/qrcode` · `apiResource /admin/tables`
- **Usuários:** `apiResource /admin/users`
- **Relatórios:** `GET /admin/reports/today` · `GET /admin/reports/period`
- **Desempenho:** `GET /admin/performance` · `GET /admin/team-performance`
- **Feedbacks:** `GET /admin/feedbacks`
- **Impressão:** `GET /admin/print/jobs` · `DELETE /admin/print/jobs/cleanup` · `POST /admin/print/jobs/{job}/retry` · `POST /admin/print/test` · `POST /admin/print/agent-token`

## Agente de impressão — `abilities:print-agent`, prefixo `/print-agent`

- `GET /print-agent/jobs/pending` · `PATCH /print-agent/jobs/{job}/printed` · `PATCH /print-agent/jobs/{job}/failed`

---

**Convenção de rotas:** nomes fixos sempre ANTES de wildcards `{param}`. Throttles: `login`, `webhook`, `api`, `print-agent`.
