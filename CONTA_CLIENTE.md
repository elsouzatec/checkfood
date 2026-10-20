# Carteira Digital — Etapa 1: Conta do Cliente · CheckFood / PagFood

> Status: **Fase 1 implementada no branch `feat/conta-cliente`** (aguardando revisão/deploy) · 2026-10-19
> "Carteira do cliente" = a **carteira digital** (saldo pré-pago) de [CARTEIRA_DIGITAL.md](CARTEIRA_DIGITAL.md).
> Aquele doc desenha saldo e ledger; este desenha a **identidade** que o saldo exige e que não existia:
> até aqui o cliente era só um telefone, sem senha — saldo preso a isso seria gasto por quem digitasse o número.
> Ordem: **conta → verificação do telefone → saldo**.

---

## 1. Decisões do dono (2026-10-19)

| # | Decisão | Consequência |
|---|---------|--------------|
| 1 | **Conta por restaurante** | A conta é o próprio `users` (`role=client`) do restaurante, agora com senha. Mesmo telefone = contas independentes em restaurantes diferentes. Sem tabela nova, sem guard novo. |
| 2 | **Senha para começar** | Sem SMS/WhatsApp/e-mail por ora. Recuperação = gerente redefine o acesso no admin. Verificação de telefone fica para a Fase 1.5 (antes do saldo). |
| 3 | **Entrega: o restaurante em que se cadastrou + as filiais dele** | Conta e carteira valem no tenant inteiro (rede), não por unidade. Parceria entre restaurantes (carteira compartilhada) só no futuro. |
| 4 | **Saldo espera o teste de campo** | Fase 2 (CARTEIRA_DIGITAL.md) continua engatilhada. |

## 2. Achados de segurança corrigidos no caminho (branch `fix/rotas-staff-exigem-papel`)

Ambos existiam na `main` e foram provados por teste antes da correção:

1. **QR da mesa autenticava funcionário pelo telefone.** `POST /sessions` buscava o usuário só por
   restaurante+telefone, sem filtrar o papel: quem digitava o telefone do gerente na mesa recebia um token
   de gerente (acesso a `/api/admin`). Agora só `role=client`. Produção verificada (leitura): nenhuma sessão
   de mesa nem token de cliente de funcionário até 19/10.
2. **Rotas de operação sem papel.** `/orders/pending`, `/orders/{id}/validate|cancel|…`, `/waiter/*`, `/tables`,
   `/restaurant/settings` só conferiam o restaurante; o cliente da mesa listava pedidos de outras mesas (com
   nome/telefone) e validava/cancelava pedidos. Agora cada rota exige o papel do app que a usa.

Com conta e senha o token do cliente passa a durar semanas — por isso as duas correções vieram antes.

## 3. Modelo

- `users` ganha `registered_at` (null = cliente anônimo da mesa), `terms_accepted_at`, `terms_version`,
  `history_visible_from` e `UNIQUE(restaurant_id, role, phone)`.
- **Conta** = `role=client` + `registered_at`. `User::hasAccount()`.
- Sessões, pedidos, pagamentos e a futura `wallets` continuam apontando para esse mesmo `users` —
  cozinha/caixa/garçom não mudam; a carteira fica naturalmente fechada por restaurante.

## 4. Fluxos

- **Cadastro** (`POST /client/register`): pelo link do restaurante (`restaurant_slug`) ou pelo QR (`table_token`).
  Se o telefone já passou pela mesa sem conta, o mesmo registro vira conta.
- **Login** (`POST /client/login`): telefone + senha. Sem restaurante informado, procura em todos; senha
  confere em mais de um → 409 `requires_restaurant` com a lista para escolher.
- **Vincular a uma mesa** (`POST /client/tables/link`): logado, escaneia o QR e entra com um toque. Resposta
  idêntica à de `POST /sessions` (token da sessão da mesa) — o resto do fluxo da mesa não muda.
  Mesa de outro restaurante → 409 `other_restaurant` (o app oferece entrar pelo telefone).
- **Telefone com conta não entra só pelo número**: `POST /sessions` → 409 `requires_login`.
- **Criar conta dentro da mesa** (`POST /client/account`): o cliente anônimo cria a senha sem sair da visita.
- **Histórico** (`GET /client/orders`): só os pedidos a partir de `history_visible_from` — quem cadastra o
  telefone de outra pessoa não herda o passado dela. Some quando houver verificação do telefone.
- **Exclusão (LGPD)** (`DELETE /client/me`, exige senha e nenhuma mesa aberta): apaga nome/telefone/senha;
  os pedidos ficam no registro do restaurante, anônimos.
- **Esqueci a senha**: gerente → Admin → Clientes → "Redefinir acesso"; o cliente se cadastra de novo.

`TableSessionService` concentra a regra de ocupação de mesa para as duas portas (telefone e conta).

## 5. Front (app cliente)

`/r/{slug}` (link do restaurante para divulgar) · `/entrar` · `/cadastro` · `/minha-conta` (vincular mesa,
meus pedidos, meus dados) · `/termos` · `/mesa/{token}` com caminho logado e "criar conta" · `/conta` dentro da mesa.
Token da conta (`pf_account_token`) separado do da mesa (`pf_token`): o fim da visita não desloga a conta.
Leitor de QR com fallback `jsQR` (iPhone não tem `BarcodeDetector`).

## 6. Riscos conhecidos (aceitos até a Fase 1.5)

- **Cadastro com telefone alheio** (sem verificação): mitigado por histórico só a partir do cadastro,
  `requires_login` e "Redefinir acesso" no admin. Solução definitiva: verificação por código.
- **Termos de uso** (`/termos`): texto-base em linguagem simples — **revisar com jurídico antes de publicar**.
  Ao mudar o texto, trocar `ClientAuthController::TERMS_VERSION` e a versão no `TermsView.vue`.

## 7. Próximas fases

### Fase 1.5 — Verificação do telefone (pré-requisito do saldo)
- [ ] Provedor de SMS/WhatsApp; `phone_verified_at`; recuperação de senha por código.
- [ ] Conta verificada: remover o filtro `history_visible_from`.

### Fase 2 — Carteira (saldo) — [CARTEIRA_DIGITAL.md](CARTEIRA_DIGITAL.md), engatilhada (teste de campo)
- [ ] `wallets.user_id` = a conta do restaurante. Saldo só em conta verificada.
- [ ] `payments` desacoplado da sessão de mesa (recarga feita pela conta, fora da mesa) — **mesmo
      pré-requisito da entrega**; fazer uma vez só.

### Fase 3 — Entrega — **básico implementado (branch `feat/entrega`, 2026-10-20)**

Como funciona:
- **Liga/desliga por restaurante** em Admin → Configurações → Entrega: taxa fixa, pedido mínimo,
  tempo estimado e área atendida (texto). Desligar para novos pedidos na hora.
- **Só cliente com conta** pede entrega: Minha conta → "Pedir para entrega" → cardápio em **modo entrega**
  (faixa laranja fixa "Pedido para ENTREGA", carrinho próprio, separado do da mesa) → finalizar
  (endereço, pagamento na entrega, troco) → **confirmação explícita do endereço** → enviado.
- **Nunca por omissão:** a API exige `fulfillment: "delivery"`, e quem está numa mesa aberta é barrado
  (409 `has_active_table`) e levado ao cardápio da mesa — ninguém pede entrega estando no salão.
- **Modelo:** cada entrega é uma sessão `type=entrega` sem mesa (`sessions_app.table_id` null), com
  endereço, taxa, forma de pagamento e troco. Os pedidos (`origin=delivery`, `table_id` null) são
  divididos por setor na aprovação como os da mesa. A taxa entra no total da sessão.
- **Restaurante (app Caixa → aba Entregas):** novas (aceitar/recusar) → em preparo (cozinha vê
  "🛵 Entrega" no KDS) → saiu para entrega → entregue → acerto na tela de sessão de sempre.
  O garçom não vê entregas.
- **Cliente acompanha** em Meus pedidos: aguardando → em preparo → saiu para entrega → entregue.

Ainda não:
- [ ] Filiais (`branches`) — a conta e a entrega já valem no restaurante inteiro; listar unidades virá com elas.
- [ ] Pagamento online antecipado (PIX pelo app) — hoje é só na entrega.
- [ ] Taxa por bairro/distância, horário de funcionamento automático e retirada no balcão.
- [ ] Faturamento de entregas nos relatórios do admin: os itens já contam; a taxa de entrega aparece no caixa, mas não nos relatórios de produtos.

## 8. Deploy

Conta do cliente — **no ar desde 2026-10-19** (`fix/rotas-staff-exigem-papel` + `feat/conta-cliente`).

Entrega (`feat/entrega`), quando aprovado:
1. Merge de `feat/entrega` na `main`.
2. `php artisan migrate --force` (1 migration: colunas de entrega + `orders.table_id` aceita null) e
   `php artisan config:cache && php artisan route:cache`.
3. `npm run build` em `frontend/apps/cliente`, `caixa`, `cozinha` e `admin` — o build JÁ publica.
