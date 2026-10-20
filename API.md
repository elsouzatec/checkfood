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
| POST | `/sessions` | Abre sessão de mesa (cliente via QR, só telefone). Telefone que tem conta → 409 `requires_login`. Só autentica `role=client`. |
| POST | `/client/register` | Cria conta de cliente no restaurante (`restaurant_slug` **ou** `table_token`, `name`, `phone`, `password` ≥ 8, `accept_terms`). Telefone já com conta → 422 `account_exists`. Throttle `client-register`. |
| POST | `/client/login` | Login da conta (`phone`, `password`, `restaurant_slug?`/`table_token?`). Senha confere em mais de um restaurante → 409 `requires_restaurant` + `restaurants[]`. Throttle `login`. |
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

### Papéis nas rotas de operação
As rotas acima de garçom/cozinha exigem papel (o cliente da mesa tem token do mesmo restaurante):
`/orders/pending|validate|quick-deliver|deliver|pick-and-deliver|cancel`, `DELETE /orders/{order}/items/*`,
`/waiter-calls/pending|dismiss` e `/waiter/*` → `waiter,manager`; `/orders/kitchen|start|ready(PATCH)` →
`kitchen,manager`; `GET /orders/ready` → `waiter,kitchen,manager`; `GET /orders/{order}`, `/tables*`,
`/restaurant/settings` → `waiter,kitchen,cashier,manager`.

## Conta do cliente — `role:client`, prefixo `/client`

Conta **por restaurante** (ver [CONTA_CLIENTE.md](CONTA_CLIENTE.md)). Token da conta: nome `client-account`, 90 dias.

| Método | Rota | Descrição |
|---|---|---|
| POST | `/client/account` | Cliente anônimo da mesa (token da sessão) cria a senha. Pedidos da visita atual entram no histórico. |
| GET | `/client/me` | Conta + restaurante + `active_session` (mesa aberta, se houver). |
| PUT | `/client/me` | `name`; troca de senha com `current_password` + `password` (derruba os outros aparelhos). |
| DELETE | `/client/me` | Exclui a conta (exige `password`; 409 com mesa aberta): anonimiza nome/telefone/senha. |
| GET | `/client/orders` | Histórico paginado (a partir de `history_visible_from`). |
| POST | `/client/tables/link` | `table_token` → abre/retoma a sessão da mesa; mesma resposta de `POST /sessions`. Mesa de outro restaurante → 409 `other_restaurant`. |
| GET | `/client/delivery` | Regras de entrega do restaurante (`delivery`), último endereço, `active_table` (mesa aberta, se houver) e formas de pagamento. |
| POST | `/client/delivery-orders` | Pedido para entrega. Body: `fulfillment: "delivery"` (obrigatório), `items[]`, `address{street, number, complement?, district, reference?}`, `payment_method` (`dinheiro`/`cartao`/`pix`, na entrega), `change_for?`, `notes?`. Erros: 422 `delivery_disabled`/`below_min_order`/`invalid_change`/`unavailable_items`; 409 `has_active_table`. Preço vem do cardápio. |

`GET /client/orders` traz `session_id` e `type` (`mesa`/`entrega`) por pedido e um mapa `deliveries` (por sessão: `stage` aguardando/em_preparo/a_caminho/entregue/cancelado, `address_line`, `fee`, `payment_method`, `change_for`).

`/client/me` (PUT/DELETE), `/client/orders` e `/client/tables/link` exigem conta (403 para o cliente anônimo da mesa).

## Caixa — `role:cashier,manager`, prefixo `/caixa`

- `GET /caixa/queue` · `GET /caixa/sessions/{session}` · `POST /caixa/sessions/{session}/confirm-payment`
- `POST /caixa/sessions/{session}/pix` · `POST /caixa/sessions/{session}/pix/cancel`
- `GET /caixa/history` · `GET /caixa/summary`
- Turno: `GET /caixa/shift` · `POST /caixa/shift/open` · `POST /caixa/shift/close`
- **Entregas:** `GET /caixa/deliveries` (em andamento + encerradas hoje) · `POST /caixa/deliveries/{session}/accept` (aprova: divide por setor, estoque, impressão) · `/dispatch` (saiu para entrega) · `/delivered` (vai para a fila do caixa, AT_CASHIER) · `/cancel` (`reason`). O acerto é o `confirm-payment` de sempre; o total inclui a taxa de entrega.
- Fila, detalhe e histórico trazem `type` (`mesa`/`entrega`) e `delivery` (cliente, endereço, taxa, troco).

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
- **Clientes:** `GET /admin/clients?search=&only_accounts=1` · `POST /admin/clients/{user}/reset-account` (apaga a senha e desconecta — recuperação de acesso)
- **Relatórios:** `GET /admin/reports/today` · `GET /admin/reports/period`
- **Desempenho:** `GET /admin/performance` · `GET /admin/team-performance`
- **Feedbacks:** `GET /admin/feedbacks`
- **Impressão:** `GET /admin/print/jobs` · `DELETE /admin/print/jobs/cleanup` · `POST /admin/print/jobs/{job}/retry` · `POST /admin/print/test` · `POST /admin/print/agent-token`

## Agente de impressão — `abilities:print-agent`, prefixo `/print-agent`

- `GET /print-agent/jobs/pending` · `PATCH /print-agent/jobs/{job}/printed` · `PATCH /print-agent/jobs/{job}/failed`

---

**Convenção de rotas:** nomes fixos sempre ANTES de wildcards `{param}`. Throttles: `login`, `webhook`, `api`, `print-agent`.
