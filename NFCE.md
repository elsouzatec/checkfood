# Plano técnico — Emissão de NFC-e · CheckFood / PagFood

> Status: **PROPOSTA — aguardando validação do provedor e do contador** · Atualizado: 2026-09-30
> Objetivo: emitir **NFC-e (modelo 65)** automaticamente ao fechar a conta no caixa, para
> que o PagFood **substitua o PDV fiscal** do restaurante em vez de complementá-lo.
> Provedor escolhido: **Focus NFe** (plano Retail → Retail+ ao passar de ~5 tenants com nota).
> Estimativa: **~19–27 dias úteis** (MVP enxuto — Fase A — em ~10 dias).

---

## 0. Pré-requisitos (antes da Fase A)

Achados no código atual que a NFC-e depende de resolver:

1. **Migration de `payments` ausente do repositório.** A tabela existe no banco de produção
   (colunas: `id, restaurant_id, session_id, amount, method, status, created_by,
   cashier_shift_id, gateway*, qr_code*, ticket_url, expires_at, customer_document,
   fiscal_status, paid_at`), mas nenhum arquivo em `database/migrations` a cria. Uma
   instalação limpa (`migrate`) quebra. Recriar a migration antes de mexer em pagamentos.
2. **Total da sessão inclui pedidos cancelados?** `CaixaController::calcSessionTotal()` e
   o cálculo em `confirmPayment()` somam os itens de **todos** os pedidos da sessão, sem
   filtrar `OrderStatus::CANCELADO` (o comentário diz "não-cancelados"). Confirmar o
   comportamento; para a nota é **obrigatório** excluir itens cancelados, e o total da
   NFC-e precisa bater com a soma dos pagamentos.
3. **Método `cartao` não distingue crédito/débito.** A NFC-e exige `tPag` específico
   (03 crédito / 04 débito). Adicionar `cartao_credito` / `cartao_debito` (e o mesmo para
   `maquininha`) no caixa, mantendo `cartao` como legado.
4. **Endereço do restaurante é texto livre** (`restaurants.address`). O emitente precisa de
   endereço estruturado (logradouro, número, bairro, CEP, código IBGE do município, UF).

## 1. Conceito

- **Uma NFC-e por conta (sessão)**, não por pedido nem por pagamento. Pagamento dividido
  (PIX + dinheiro, por exemplo) vira **uma** nota com várias formas de pagamento (`detPag`).
- Emissão **assíncrona**: o fechamento da conta nunca espera a SEFAZ. O caixa libera a
  mesa na hora; a nota é emitida por um job na fila (Redis) e o DANFE sai na impressora
  térmica quando autorizada.
- O PagFood **não fala com a SEFAZ**. Monta um JSON e o envia ao provedor, que assina,
  transmite, controla a numeração e guarda os XMLs.

## 2. Decisões travadas

1. **Provedor via interface** (`FiscalProviderInterface` + factory), no molde de
   `App\Services\Payment\PaymentGatewayInterface`. Trocar de provedor mexe em uma classe.
2. **Emite para toda conta fechada com pagamento** quando `fiscal_enabled = true`.
   **Sem filtro por meio de pagamento**: o sistema não oferece "emitir só no cartão".
3. **Não emite** quando a sessão é encerrada sem pagamento
   (`AdminTableController` força o fechamento e cancela os pedidos — linha ~314).
4. **Certificado A1 não fica no nosso banco.** O upload vai direto para o provedor via API;
   a senha do certificado não é persistida nem logada.
5. **MVP = Simples Nacional + uma UF** (a do primeiro cliente). Regime Normal e outras UFs
   ficam para a Fase C.
6. **Perfis tributários** (NCM/CFOP/CSOSN) definidos **com contador parceiro**. O sistema
   oferece defaults, mas a responsabilidade pelo enquadramento é do restaurante/contador —
   deixar explícito no contrato.

## 3. Modelagem de dados

### 3.1 `restaurants` — dados do emitente (novas colunas)

| Coluna | Tipo | Notas |
|---|---|---|
| `fiscal_enabled` | bool, default false | liga a emissão |
| `fiscal_environment` | enum `homologacao`/`producao` | default `homologacao` |
| `cnpj` | string(14) | só dígitos |
| `razao_social` | string | nome fantasia continua em `name` |
| `inscricao_estadual` | string(20) | |
| `regime_tributario` | tinyint | 1 = Simples, 2 = Simples excesso, 3 = Normal (CRT) |
| `addr_logradouro`, `addr_numero`, `addr_bairro`, `addr_cep`, `addr_municipio`, `addr_codigo_ibge`, `addr_uf` | strings | endereço estruturado (§0.4) |
| `nfce_serie` | smallint, default 1 | |
| `nfce_csc_id` | string | ID do CSC (token SEFAZ) |
| `nfce_csc_token` | text, **encrypted + hidden** | |
| `fiscal_provider_token` | text, **encrypted + hidden** | token da empresa no Focus |
| `fiscal_certificate_expires_at` | date, nullable | para alertar vencimento |

Mesma regra das credenciais `mercadopago_*`: `encrypted` no cast, `hidden` no model,
escrita só por endpoint dedicado.

### 3.2 `tax_profiles` (por restaurante)

Perfil tributário reutilizável, para o gerente não preencher item a item.

| Coluna | Notas |
|---|---|
| `restaurant_id` | `BelongsToRestaurant` |
| `name` | ex.: "Refeição", "Bebida não alcoólica", "Cerveja (ST)" |
| `ncm` | 8 dígitos |
| `cfop` | ex.: 5102 (tributado), 5405 (ST) |
| `csosn` | ex.: 102, 500 (Simples) |
| `cst_icms`, `aliquota_icms` | nullable — Regime Normal (Fase C) |
| `origem` | default 0 |
| `cest` | nullable — obrigatório em itens com ST |
| `cst_pis`, `cst_cofins` | default 49 no Simples (confirmar com contador) |
| `cclass_trib` | nullable — reforma tributária (IBS/CBS), ver §11 |
| `is_default` | um por restaurante |

Resolução do perfil de um item: `product.tax_profile_id` → `category.tax_profile_id` →
perfil `is_default` do restaurante. Sem perfil resolvido → a nota **não** é enviada
(status `error`, mensagem clara no caixa).

Novas FKs nullable: `products.tax_profile_id`, `categories.tax_profile_id`.

### 3.3 `fiscal_documents`

Uma linha por tentativa de NFC-e de uma sessão. É a fonte de verdade do status fiscal.

| Coluna | Notas |
|---|---|
| `restaurant_id`, `session_id` | `BelongsToRestaurant`; índice em `session_id` |
| `ref` | string **unique** — referência idempotente enviada ao provedor (§5) |
| `provider` | `focusnfe` |
| `environment` | copiado do restaurante no momento da emissão |
| `status` | `pending` → `processing` → `authorized` / `rejected` / `error` / `cancelled` / `contingency` |
| `customer_document` | CPF/CNPJ opcional na nota |
| `amount` | total da nota |
| `payload` | json — snapshot do que foi enviado (auditoria) |
| `numero`, `serie`, `chave`, `protocolo` | retorno da SEFAZ |
| `qrcode_url`, `url_consulta`, `xml_url`, `danfe_url` | retorno do provedor |
| `error_code`, `error_message` | rejeição da SEFAZ ou erro do provedor |
| `attempts` | |
| `authorized_at`, `cancelled_at`, `cancel_reason`, `cancelled_by` | |

Colunas legadas `orders.fiscal_status`, `orders.customer_document`,
`payments.fiscal_status`, `payments.customer_document`: **não usar** para o fluxo novo
(ficam como estão; remover numa limpeza futura).

## 4. Camada de serviço

```
app/Services/Fiscal/
├── FiscalProviderInterface.php
├── FiscalProviderFactory.php      forRestaurant(Restaurant): FiscalProviderInterface
├── FocusNfeProvider.php           HTTP client (Http facade), token por empresa
├── NfcePayloadBuilder.php         sessão → array neutro (itens, pagamentos, emitente)
└── FiscalService.php              orquestra: cria FiscalDocument, despacha job, trata retorno
```

```php
interface FiscalProviderInterface
{
    /** Emite NFC-e. Retorna status normalizado + dados da SEFAZ quando síncrono. */
    public function issueNfce(FiscalDocument $doc, array $payload): FiscalResult;

    /** Consulta status por ref (reconciliação e retry). */
    public function getNfce(string $ref): FiscalResult;

    /** Cancela (justificativa 15–255 caracteres). */
    public function cancelNfce(string $ref, string $reason): FiscalResult;

    /** Onboarding do tenant: cria/atualiza empresa e envia certificado A1. */
    public function upsertCompany(Restaurant $restaurant): string;   // retorna token da empresa
    public function uploadCertificate(Restaurant $restaurant, string $pfxBinary, string $password): Carbon; // validade
}
```

`FiscalResult` é um DTO simples (status normalizado, campos da SEFAZ, erro). O
`NfcePayloadBuilder` produz um formato **nosso**; a tradução para o JSON do Focus fica
dentro de `FocusNfeProvider` — é isso que torna o provedor trocável.

### Mapeamento de pagamento (`tPag`)

| `payments.method` | `tPag` | Observação |
|---|---|---|
| `dinheiro` | 01 | informar troco (`vTroco`) se houver |
| `cartao_credito` / `maquininha` crédito | 03 | integrado (MP Point): enviar CNPJ da credenciadora + autorização se disponível |
| `cartao_debito` / `maquininha` débito | 04 | idem |
| `pix` (Mercado Pago, QR dinâmico) | 17 | |
| `cartao` (legado) | 99 "Outros" | só para dados antigos — evitar |

## 5. Fluxo de emissão

1. **Gatilho:** a sessão passa para `CLOSED` com pagamento. Hoje isso acontece em dois
   lugares — `CaixaController::confirmPayment()` (linha ~269) e
   `PaymentService` (webhook PIX, linha ~172). Centralizar: ambos chamam
   `FiscalService::onSessionPaid($session)`, **após o commit**
   (`DB::afterCommit` / `dispatch()->afterCommit()`).
2. `FiscalService` verifica `fiscal_enabled`, cria o `FiscalDocument` (`pending`,
   `ref = "r{restaurant_id}-s{session_id}-{seq}"`) e despacha `EmitNfceJob` na fila `fiscal`.
3. **`EmitNfceJob`** (`ShouldBeUnique` por sessão, `tries` 5, backoff 10s/30s/2min/10min):
   - roda **sem usuário autenticado** → o global scope de tenant é no-op; carregar tudo
     explicitamente por `restaurant_id` do documento;
   - monta o payload (itens não cancelados agregados por produto, pagamentos `paid`,
     CPF), grava o snapshot em `payload`, chama `issueNfce`;
   - a NFC-e no Focus normalmente responde **síncrona**: `authorized` → grava dados e
     enfileira o DANFE na impressora (§7); `rejected` → grava erro e **para** (retry não
     resolve rejeição);
   - timeout/5xx → **reenvia com a mesma `ref`** (o provedor deduplica — nunca gerar
     duas notas para a mesma conta).
4. **Reconciliação:** comando agendado `fiscal:reconcile` (a cada 10 min) consulta
   `getNfce` para documentos presos em `pending`/`processing`/`contingency` há mais de
   10 min. Agendar junto de `billing:check-overdue`.
5. **Webhook do provedor** (opcional se a emissão for síncrona; necessário para
   contingência): `POST /api/webhooks/fiscal/focusnfe`. Autenticar com segredo por header
   e **rejeitar se o segredo não estiver configurado** — não repetir o bypass do webhook
   do Mercado Pago.

### Reemissão

Nota `rejected`/`error` (ex.: NCM inválido) → gerente corrige o perfil tributário → caixa
clica "Reemitir" → novo `FiscalDocument` com `seq + 1`. O anterior fica no histórico.

## 6. Cancelamento

- Endpoint `POST /api/caixa/fiscal-documents/{id}/cancel` (cashier, manager), justificativa
  obrigatória (15–255 caracteres).
- Prazo legal curto (em geral **30 min** após a autorização; varia por UF). A UI mostra o
  tempo restante e esconde o botão depois do prazo. Fora do prazo, orientar o restaurante
  a tratar com o contador — o sistema não oferece atalho.
- Cancelar a nota **não** estorna pagamento nem reabre a sessão (são coisas diferentes).

## 7. Impressão do DANFE NFC-e

- `print_jobs` hoje é por pedido (`order_id` + `payload` snapshot). Adicionar coluna
  `kind` (`order` | `nfce`, default `order`) e `fiscal_document_id` nullable.
- Payload `nfce`: emitente, itens, totais, formas de pagamento, chave de acesso formatada,
  protocolo, URL de consulta e **conteúdo do QR code** (`qrcode_url`).
- O agente térmico precisa imprimir QR code (ESC/POS `GS ( k`). **Verificar a
  capacidade do agente atual** — é o maior risco da etapa.
- Sem impressora (`print_enabled = false`): o caixa mostra o DANFE (`danfe_url`) e um QR
  para o cliente consultar/baixar a nota no celular.

## 8. Telas

**Plataforma (super admin) — implantação feita pela PagFood:**
- Aba "Fiscal" no restaurante: CNPJ, razão social, IE, regime, endereço estruturado,
  série, CSC, ambiente, upload do certificado A1 (+ senha, só em trânsito), validade.
- Botão "Testar emissão" (em homologação).
- Alerta de certificado vencendo (30 dias antes) na listagem de tenants.

**Admin (gerente):**
- CRUD de perfis tributários; seletor de perfil na categoria e no produto.
- Relatório de notas por período (autorizadas, canceladas, rejeitadas, total emitido).
- Fase B: exportação de XMLs do mês (ZIP) para o contador.

**Caixa:**
- Campo opcional "CPF na nota" ao confirmar pagamento (validação de dígito).
- Crédito/débito separados nos meios de pagamento (§0.3).
- Status da nota no histórico da sessão; botões "Reemitir", "Cancelar nota",
  "Reimprimir DANFE".

## 9. Segurança e multi-tenancy

- `FiscalDocument` e `TaxProfile` usam `BelongsToRestaurant`. Endpoints de tenant filtram
  por `restaurant_id` explicitamente (como o `CaixaController` já faz).
- Job, webhook e comando rodam **sem autenticação** → scope no-op. Toda query ali filtra
  por `restaurant_id` à mão; teste de isolamento cobrindo isso em
  `tests/Feature/TenantIsolationTest.php`.
- Tokens (`fiscal_provider_token`, `nfce_csc_token`) `encrypted` + `hidden`. A senha do
  certificado nunca é persistida nem logada; o log do provedor mascara o token.
- Produção só com `fiscal_environment = producao` definido pelo super admin.

## 10. Plano faseado / checklist

### Fase 0 — Pré-requisitos (~2–3 dias)
- [ ] Recriar migration de `payments` (§0.1)
- [ ] Confirmar/corrigir total da sessão sem pedidos cancelados (§0.2)
- [ ] Crédito/débito no caixa (§0.3)
- [ ] Endereço estruturado do restaurante (§0.4)
- [ ] Conta de teste no Focus NFe; confirmar os pontos da §11 com o suporte deles

### Fase A — MVP emissão (~10 dias)
- [ ] Migrations: colunas fiscais em `restaurants`, `tax_profiles`, FKs, `fiscal_documents`
- [ ] `FiscalProviderInterface`, `FocusNfeProvider`, factory, `NfcePayloadBuilder`
- [ ] `FiscalService` + `EmitNfceJob` + gatilho centralizado no fechamento
- [ ] Tela fiscal na plataforma + upload de certificado
- [ ] CRUD de perfis tributários no admin
- [ ] CPF na nota + status no caixa
- [ ] Impressão do DANFE no agente
- [ ] Testes: payload builder (unit), fluxo com provider fake, isolamento multi-tenant
- [ ] Homologação completa na SEFAZ da UF do primeiro cliente

### Fase B — Operação (~5–7 dias)
- [ ] Cancelamento com prazo na UI
- [ ] Reemissão após rejeição
- [ ] `fiscal:reconcile` agendado + webhook do provedor
- [ ] Contingência offline (conforme suporte do provedor)
- [ ] Relatório de notas + export de XMLs para o contador
- [ ] Alerta de certificado vencendo

### Fase C — Expansão (sob demanda)
- [ ] Regime Normal (CST + alíquotas)
- [ ] Outras UFs
- [ ] Campos da reforma tributária (IBS/CBS) em produção
- [ ] Migração para Focus Retail+ (conta única multi-CNPJ) e rateio de custo por tenant
- [ ] Add-on "Nota fiscal" no billing da plataforma (`Subscription`)

## 11. Questões em aberto

**Com o Focus NFe:**
1. Cadastro de empresa e upload de certificado **via API** estão disponíveis no plano
   Retail, ou só no Retail+/revenda?
2. Como funciona a contingência offline de NFC-e e o que chega no webhook?
3. CSC é configurado por empresa na API?
4. Quem controla a numeração (série/número) — o Focus ou nós?

**Com o contador parceiro:**
5. Perfis tributários padrão para restaurante no Simples (refeição, bebidas, bebidas com ST).
6. **Taxa de serviço (10%)** e gorjeta (`tip_enabled`) entram na NFC-e? Como?
7. **Carteira digital** (ver `CARTEIRA_DIGITAL.md`): recarga não gera nota e o consumo
   gera, com `tPag` 05 (crédito loja)? Confirmar antes da Fase A da carteira.
8. Reforma tributária: quais campos IBS/CBS (`cClassTrib`) são exigidos na NFC-e em
   2026–2027 para o Simples, e quando passam a valer de fato.

**Comercial:**
9. Preço do add-on fiscal (custo do provedor ≈ R$ 60–110/restaurante/mês no Retail) e
   taxa de implantação (certificado, CSC, credenciamento na SEFAZ).
