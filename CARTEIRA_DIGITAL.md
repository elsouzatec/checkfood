# Proposta — Carteira Digital (saldo pré-pago) · CheckFood / PagFood

> Status: **ENGATILHADO — aguardando teste de campo** · Atualizado: 2026-06-05
> Plano pronto para execução; **não iniciar a Fase A** até a conclusão do teste de campo
> do sistema atual em produção. Ver §0 (Gatilho de início).
> Objetivo: dar a cada restaurante uma **carteira de saldo pré-pago** para seus clientes —
> cumulativa entre visitas, consumível nos pedidos, com **bônus de recarga** e **cashback**,
> tudo **configurável por restaurante** (filosofia de relacionamento própria de cada tenant).

---

## 0. Gatilho de início (engatilhado)

Este roteiro está **pronto, porém parado**. A prioridade imediata é um **teste de campo do
que já existe** — o sistema atual em produção (cardápio, pedidos, cozinha, caixa, garçom,
pagamentos, plataforma/billing). A carteira **só entra em desenvolvimento depois** que esse
teste de campo validar o sistema atual em uso real.

**Para disparar a Fase A, dois pré-requisitos:**
1. Teste de campo do sistema atual concluído (sem pendências que mudem premissas de pagamento).
2. As 4 questões em aberto da §11 respondidas.

Enquanto isso, nada de migrations/models de carteira. O documento serve de plano congelado.

## 1. Conceito

Carteira = **stored-value fechado por restaurante** (estilo gift card / "vale"), ancorado na
**conta do cliente** daquele restaurante: o `User` (`role: client`) com senha — ver
[CONTA_CLIENTE.md](CONTA_CLIENTE.md) (Etapa 1, implementada em 2026-10-19). Antes disso o
cliente era só um telefone, sem senha, o que não serve para guardar dinheiro. O saldo exige
ainda **telefone verificado** (Fase 1.5 de CONTA_CLIENTE.md). O cliente carrega crédito, o
saldo persiste entre visitas e é consumido como **método de pagamento** dos pedidos.

Três fontes de crédito, um débito:

| Movimento    | Origem                          | Natureza            | Sacável/Reembolsável |
|--------------|----------------------------------|---------------------|----------------------|
| **Recarga**  | cliente paga (PIX/cartão via MP) | passivo (dinheiro)  | princípio: não¹      |
| **Bônus**    | cortesia na recarga              | despesa marketing   | não                  |
| **Cashback** | % do consumo                     | despesa marketing   | não                  |
| **Consumo**  | pagamento de pedido              | baixa do passivo    | —                    |

¹ Decisão travada: **sem saque**. Saldo só consome em pedido (ver §8).

## 2. Decisões travadas

1. **Custódia do dinheiro = conta Mercado Pago do próprio restaurante.** A PagFood **apenas
   escritura o ledger**; o *float* e a obrigação de honrar o saldo são do restaurante. Postura
   de "SaaS de software", não de custodiante — a mais defensável (ver §9).
2. **Saldo fechado por restaurante** (só vale onde foi carregado). Alinhado ao isolamento
   multi-tenant já existente e testado.
3. **Sem saque / sem reembolso por padrão** (estilo gift card). Reembolso do principal pode
   ser um *toggle* por restaurante (default desligado).
4. **Recarga com valor mínimo** e **bônus como linha separada** no ledger.

## 3. Princípio-mestre: tudo configurável por tenant

A carteira é um **motor de regras**. Cada restaurante liga/desliga e calibra. Nenhuma regra
de bônus/cashback/expiração é hard-coded — vive em configuração editável pelo gerente
(`/api/admin/...`). Defaults conservadores (carteira **desligada** ao criar o restaurante).

### Knobs configuráveis (por restaurante)
- `wallet_enabled` — liga/desliga a carteira inteira.
- **Nome/branding** — como o restaurante chama ("Créditos", "Cashback", "Saldo").
- **Recarga**: valor mínimo; valores sugeridos (atalhos no app).
- **Bônus de recarga**: ligado/desligado; **faixas** (tiers) — ex.: `≥100 → +10%`,
  `≥200 → +15%` (percentual ou valor fixo).
- **Cashback**: ligado/desligado; percentual; teto por período (opcional).
- **Expiração**: dias para **bônus** e **cashback** expirarem (principal **não** expira — CDC).
- **Ordem de consumo**: bônus-primeiro (default, reduz passivo de cortesia e expira menos) ou
  principal-primeiro.
- **Reembolso de principal**: permitido? (default não).

## 4. Modelagem de dados

### 4.1 `wallets` (saldo cacheado; 1 por cliente+restaurante)
| Coluna             | Tipo            | Nota |
|--------------------|-----------------|------|
| id                 | bigint PK       | |
| restaurant_id      | FK restaurants  | trait `BelongsToRestaurant` |
| user_id            | FK users        | o cliente |
| balance_principal  | decimal(10,2)   | dinheiro carregado (reembolsável) |
| balance_bonus      | decimal(10,2)   | bônus + cashback (cortesia, expirável) |
| timestamps         |                 | |

`UNIQUE(restaurant_id, user_id)`. **Saldo total = principal + bonus.** Os campos são *cache*;
a **fonte da verdade é o ledger** (§4.2), atualizado na mesma transação de cada movimento, com
comando de reconciliação.

### 4.2 `wallet_transactions` (ledger **append-only**)
| Coluna          | Tipo           | Nota |
|-----------------|----------------|------|
| id              | bigint PK      | |
| restaurant_id   | FK             | `BelongsToRestaurant` |
| wallet_id       | FK wallets     | |
| type            | string(20)     | `recarga` `bonus` `cashback` `consumo` `estorno` `expiracao` `ajuste` |
| bucket          | string(10)     | `principal` \| `bonus` (qual saldo afeta) |
| amount          | decimal(10,2)  | **com sinal**: + crédito, − débito |
| balance_after   | decimal(10,2)  | snapshot p/ auditoria |
| payment_id      | FK payments?   | liga recarga/consumo ao Payment MP |
| order_id        | FK orders?     | consumo/cashback |
| idempotency_key | string unique? | **antifraude de webhook** (§6) |
| meta            | json           | regra aplicada (tier, % cashback) |
| description     | string?        | |
| created_by      | FK users?      | gerente, em `ajuste` |
| timestamps      |                | |

Nunca há "campo saldo mutável solto": todo saldo nasce de somar o ledger.

### 4.3 Configuração — em `restaurants`
- `wallet_enabled` boolean (cast boolean, default false)
- `wallet_config` **json** (cast `array`): `{ nome, recarga_min, recarga_sugestoes[],
  bonus_enabled, bonus_tiers[{min, percent|fixo}], cashback_enabled, cashback_percent,
  cashback_teto, bonus_expira_dias, cashback_expira_dias, ordem_consumo, reembolso_principal }`

(JSON acomoda as faixas/regras-lista sem explodir colunas. Alternativa relacional:
tabela-filha `wallet_bonus_tiers` se a UI de admin pedir — decidir na Fase B.)

### 4.4 Novo método de pagamento
`PaymentController::pay` valida `in:pix,dinheiro,cartao` → adicionar **`saldo`**.

## 5. Fluxo de recarga
1. Cliente escolhe valor (≥ `recarga_min`); app mostra **bônus previsto** ("põe 100, recebe 110").
2. Cria `Payment` tipo *recarga* (target = wallet, não a sessão de mesa) e dispara PIX/cartão MP.
3. Cliente paga.
4. **Webhook MP confirma** → numa **transação DB**, com `idempotency_key = payment_id`:
   lança `recarga` (principal) + `bonus` (se faixa aplicável), atualiza `wallets`.
5. App reflete o novo saldo (polling do payment, como já é hoje).

## 6. Fluxo de consumo
1. No fechamento da conta, método **`saldo`** aparece se `wallet_enabled` e saldo > 0.
2. Permite **pagamento total ou parcial** (resto em outro método).
3. Numa **transação DB com trava** na wallet: valida saldo suficiente → debita conforme
   `ordem_consumo` (bônus-primeiro por default) → lança `consumo` → marca Payment pago →
   `Order::transitionTo(PAGO)`.
4. Se `cashback_enabled`: após consumo confirmado, credita `cashback` (bucket bonus),
   **idempotente por order/payment**.

## 7. Estornos
- **Pedido cancelado pós-pagamento por saldo** → relança crédito (`estorno`) nos mesmos buckets.
- **Recarga estornada / chargeback** → debita; tratar saldo negativo (bloquear consumo,
  alertar gerente). Regra antifraude: estorno de cartão **só** afeta o que ainda não virou bônus.

## 8. Segurança e multi-tenancy
- `wallets` e `wallet_transactions` usam `BelongsToRestaurant` → saldo de um tenant **nunca**
  visível/usável em outro (a invariante que blindamos em `TenantIsolationTest`).
- **Idempotência forte** na recarga: o retry do webhook MP **não** pode creditar duas vezes
  (conecta ao endurecimento recente do `WebhookController`).
- **Débito atômico**: `SELECT ... FOR UPDATE` na wallet dentro da transação evita gasto-duplo
  (dois aparelhos pagando a mesma conta).
- Autorização: cliente só acessa a **própria** wallet (padrão de `authorizePayment`).

## 9. Impactos a gerenciar (não bloqueiam — orientam)
- **Regulatório (BR):** mantendo o float na conta MP do restaurante + saldo fechado por loja,
  fica no território **gift card/vale** (closed-loop), bem mais leve que carteira aberta. A
  PagFood não custodia dinheiro de terceiros. Revisar com contador/jurídico antes do go-live.
- **Contábil (restaurante):** saldo principal = **passivo (receita diferida)**; bônus/cashback
  concedidos = **despesa de marketing**. Painel do gerente deve separar os dois.
- **CDC:** principal pago **não expira** trivialmente; **bônus/cashback (cortesia) podem**
  expirar — por isso buckets separados no ledger.

## 10. Plano faseado / checklist

### Fase A — Núcleo (MVP do pré-pago cumulativo)
- [ ] Migrations: `wallets`, `wallet_transactions`, `restaurants.wallet_enabled` + `wallet_config`.
- [ ] Models `Wallet`/`WalletTransaction` com `BelongsToRestaurant` + `HasFactory`.
- [ ] `WalletService`: `credit()`, `debit()`, `balanceOf()` — tudo via ledger + transação.
- [ ] Recarga: endpoint + `Payment` tipo recarga + confirmação idempotente no webhook.
- [ ] Pagamento por `saldo` no `PaymentController` (total/parcial) + débito atômico.
- [ ] Testes: isolamento tenant na wallet, idempotência de recarga, gasto-duplo, saldo insuficiente.

### Fase B — Incentivos (o que cativa)
- [ ] Engine de **bônus de recarga** (faixas) + **cashback**, lendo `wallet_config`.
- [ ] UI admin: editar regras; UI cliente: ver bônus previsto, extrato, saldo.

### Fase C — Controles e transparência
- [ ] Painel gerente: passivo em aberto (principal vs bônus), movimentações, ajustes manuais.
- [ ] Extrato do cliente + política de estorno/chargeback + expiração (job agendado).

## 11. Questões em aberto
- Recarga: PIX **e** cartão, ou só PIX no MVP (menos risco de chargeback)?
- Bônus por faixa **percentual** vs **valor fixo** — suportar ambos? (JSON já acomoda.)
- ~~Saldo por filial ou compartilhado na rede?~~ **Respondida (2026-10-19): por restaurante
  (tenant), valendo em todas as filiais dele.** Carteira compartilhada entre restaurantes só
  se um dia houver parceria entre eles.
- Expiração: job diário (reaproveitar o cron do scheduler que já roda às 03:00).
