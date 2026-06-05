<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import OrderCard from '../components/OrderCard.vue'
import BillSummary from '../components/BillSummary.vue'

const route  = useRoute()
const router = useRouter()

const table         = ref(null)
const orders        = ref([])
const session       = ref(null)   // sessão ativa da mesa (guest ou cliente)
const loading       = ref(true)
const actionLoading = ref(null)
let   pollInterval  = null

// ── Configurações do restaurante ──
const terminalEnabled    = ref(false)
const terminalAcquirer   = ref('mercadopago')
const allowClientPayment = ref(false)
const cashierMode        = ref(false)
const tipEnabled         = ref(false)
const tipDefaultPercent  = ref(10)
const printEnabled       = ref(false)

// ── Taxa de serviço — valor capturado do BillSummary ao fechar conta ──
const tipAmountRef = ref(0)

// ── Payment modal ──
const payModal       = ref(false)
const payStep        = ref('who_pays')
const payLoading     = ref(false)
const payError       = ref('')
const currentPayment = ref(null)
const pendingCount   = ref(0)
let   payPollInterval = null

// ── Labels originais ──
const statusLabels = {
  pendente_validacao: 'Pendente',
  confirmado:         'Em fila',
  preparando:         'Preparando',
  pronto:             'Pronto',
  entregue:           'Entregue',
  cancelado:          'Cancelado',
}
const areaLabels = {
  cozinha:    '🍳 Cozinha',
  bar:        '🍺 Bar',
  lanchonete: '🍟 Lanchonete',
}
// Cores das áreas harmonizadas com a paleta
// (vermelho p/ cozinha, azul-slate p/ bar, âmbar p/ lanchonete)
const areaColors = {
  cozinha:    '#E63946',
  bar:        '#457B9D',
  lanchonete: '#B8860B',
}

// ────────────────────────────────────────────
async function fetchOrders() {
  try {
    const { data } = await api.get(`/tables/${route.params.id}/orders`)
    table.value   = data.table
    orders.value  = data.orders
    session.value = data.session ?? null
  } catch (err) {
    console.error('Erro:', err)
  } finally {
    loading.value = false
  }
}

async function fetchSettings() {
  try {
    const { data } = await api.get('/restaurant/settings')
    terminalEnabled.value    = data.terminal_integration_enabled
    terminalAcquirer.value   = data.terminal_acquirer
    allowClientPayment.value = data.allow_client_payment
    cashierMode.value        = !!data.cashier_mode
    tipEnabled.value         = data.tip_enabled         ?? false
    tipDefaultPercent.value  = data.tip_default_percent ?? 10
    printEnabled.value       = !!data.print_enabled
  } catch (e) {
    console.warn('Configurações não carregadas:', e?.response?.status)
  }
}

function groupItemsByArea(items) {
  const groups = {}
  for (const item of items) {
    const area = item.product?.category?.preparation_area || 'cozinha'
    if (!groups[area]) groups[area] = []
    groups[area].push(item)
  }
  return groups
}

function canQuickDeliver(order) {
  if (order.status !== 'confirmado') return false
  return order.items.every(item => {
    const area = item.product?.category?.preparation_area
    return area === 'bar' || area === 'lanchonete'
  })
}

function orderTotal(order) {
  return order.items.reduce((sum, item) => sum + parseFloat(item.unit_price) * item.quantity, 0)
}

function totalGeral() {
  return orders.value.reduce((sum, order) => sum + orderTotal(order), 0)
}

function formatPrice(value) {
  return parseFloat(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

// Nome do cliente — prioriza guest_name (sessões abertas pelo garçom)
function clientName() {
  if (session.value?.guest_name) return session.value.guest_name
  if (session.value?.user?.name) return session.value.user.name
  return null
}

// ────────────────────────────────────────────
// Ações originais nos pedidos
// ────────────────────────────────────────────
async function approveOrder(orderId) {
  actionLoading.value = `approve-${orderId}`
  try {
    await api.patch(`/orders/${orderId}/validate`)
    await fetchOrders()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao aprovar')
  } finally { actionLoading.value = null }
}

async function rejectOrder(orderId) {
  if (!confirm('Cancelar este pedido?')) return
  actionLoading.value = `reject-${orderId}`
  try {
    await api.patch(`/orders/${orderId}/cancel`)
    await fetchOrders()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao cancelar')
  } finally { actionLoading.value = null }
}

async function quickDeliverOrder(orderId) {
  if (!confirm('Confirmar entrega deste pedido?')) return
  actionLoading.value = `quick-${orderId}`
  try {
    await api.patch(`/orders/${orderId}/quick-deliver`)
    await fetchOrders()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao confirmar entrega')
  } finally { actionLoading.value = null }
}

async function deliverOrder(orderId) {
  actionLoading.value = `deliver-${orderId}`
  try {
    await api.patch(`/orders/${orderId}/deliver`)
    await fetchOrders()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao confirmar entrega')
  } finally { actionLoading.value = null }
}

async function pickAndDeliverOrder(orderId) {
  actionLoading.value = `pick-${orderId}`
  try {
    await api.patch(`/orders/${orderId}/pick-and-deliver`)
    await fetchOrders()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao confirmar entrega')
  } finally { actionLoading.value = null }
}

async function removeItem(orderId, itemId) {
  if (!confirm('Remover este item?')) return
  try {
    await api.delete(`/orders/${orderId}/items/${itemId}`)
    await fetchOrders()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao remover item')
  }
}

// ────────────────────────────────────────────
// Encaminhamento ao caixa físico
// ────────────────────────────────────────────
const forwardMethod  = ref('')
const forwardLoading = ref(false)
const forwardError   = ref('')

async function openCloseModal({ tipAmount = 0 } = {}) {
  tipAmountRef.value   = tipAmount
  payError.value       = ''
  pendingCount.value   = 0
  currentPayment.value = null
  forwardError.value   = ''
  forwardMethod.value  = ''
  payModal.value       = true

  if (cashierMode.value) {
    payStep.value = 'forward_cashier'
    return
  }

  if (!allowClientPayment.value) {
    await requestClose(false, true)
  } else {
    payStep.value = 'who_pays'
  }
}

async function confirmForwardCashier() {
  if (!forwardMethod.value) {
    forwardError.value = 'Selecione o meio de pagamento antes de encaminhar.'
    return
  }

  // session.value pode ser null se a sessão estava em closing (tentativa de
  // pagamento anterior). Fallback: pega o session_id do primeiro pedido ativo.
  const sessionId = session.value?.id ?? orders.value[0]?.session_id
  if (!sessionId) {
    forwardError.value = 'Sessão não encontrada. Recarregue a página e tente novamente.'
    return
  }

  forwardLoading.value = true
  forwardError.value   = ''

  try {
    await api.post(`/waiter/sessions/${sessionId}/forward-cashier`, {
      payment_method: forwardMethod.value,
    })
    payStep.value = 'forwarded'
    setTimeout(() => { payModal.value = false; router.push('/mesas') }, 2500)
  } catch (err) {
    forwardError.value = err.response?.data?.message || 'Erro ao encaminhar ao caixa.'
  } finally {
    forwardLoading.value = false
  }
}

async function requestClose(force = false, goToMethods = true) {
  const activeOrder = orders.value.find(o => !['cancelado', 'pago'].includes(o.status))
  if (!activeOrder) {
    payError.value = 'Nenhum item a pagar.'
    return
  }

  payLoading.value = true
  payError.value   = ''

  try {
    const url     = `/sessions/${activeOrder.session_id}/close${force ? '?force=true' : ''}`
    const payload = tipAmountRef.value > 0 ? { tip_amount: tipAmountRef.value } : {}
    const { data } = await api.post(url, payload)
    currentPayment.value = data.payment
    pendingCount.value   = 0
    payStep.value        = goToMethods ? 'methods' : 'client_paying'
  } catch (err) {
    const res = err.response?.data
    if (err.response?.status === 422 && res?.requires_force) {
      pendingCount.value = res.pending_count
      payStep.value = 'pending_warning'
      pendingGoToMethods.value = goToMethods
      return
    }
    payError.value = res?.message || 'Erro ao fechar conta.'
  } finally {
    payLoading.value = false
  }
}

const pendingGoToMethods = ref(true)

async function confirmForceClose() {
  await requestClose(true, pendingGoToMethods.value)
}

async function waiterWillPay() {
  await requestClose(false, true)
}

async function clientWillPay() {
  await requestClose(false, false)
}

async function selectPix() {
  if (!currentPayment.value) return
  payLoading.value = true
  payError.value   = ''
  try {
    const { data } = await api.post(`/payments/${currentPayment.value.id}/pay`, { method: 'pix' })
    currentPayment.value = data.payment
    payStep.value        = 'pix'
    startPaymentPolling()
  } catch (err) {
    payError.value = err.response?.data?.message || 'Erro ao gerar Pix.'
  } finally {
    payLoading.value = false
  }
}

async function selectManual(method) {
  if (!currentPayment.value) return
  payLoading.value = true
  payError.value   = ''
  try {
    await api.post(`/payments/${currentPayment.value.id}/pay`, { method })
    payStep.value = 'done'
    scheduleDone()
  } catch (err) {
    payError.value = err.response?.data?.message || 'Erro ao registrar pagamento.'
  } finally {
    payLoading.value = false
  }
}

async function selectTerminal() {
  if (!currentPayment.value) return
  payLoading.value = true
  payError.value   = ''
  try {
    const backUrl = `${window.location.origin}/garcom/retorno`
    const { data } = await api.post(`/payments/${currentPayment.value.id}/deep-link`, { back_url: backUrl })
    console.log('Deep link gerado:', data.deep_link)
    currentPayment.value = data.payment
    window.location.href = data.deep_link
    payStep.value = 'terminal_waiting'
  } catch (err) {
    payError.value = err.response?.data?.message || 'Erro ao acionar terminal.'
  } finally {
    payLoading.value = false
  }
}

async function copyPixCode() {
  if (!currentPayment.value?.qr_code) return
  try {
    await navigator.clipboard.writeText(currentPayment.value.qr_code)
  } catch {
    const el = document.createElement('textarea')
    el.value = currentPayment.value.qr_code
    document.body.appendChild(el); el.select()
    document.execCommand('copy')
    document.body.removeChild(el)
  }
  alert('Código copiado!')
}

function startPaymentPolling() {
  stopPaymentPolling()
  payPollInterval = setInterval(async () => {
    if (!currentPayment.value) return

    try {
      const { data } = await api.get(`/payments/${currentPayment.value.id}`)
      currentPayment.value = data.payment
      const status = data.payment.status

      // Fluxo feliz — pagamento confirmado
      if (status === 'paid') {
        stopPaymentPolling()
        payStep.value = 'done'
        scheduleDone()
        return
      }

      // Pagamento morreu no gateway — volta à seleção de método
      if (status === 'expired' || status === 'failed') {
        stopPaymentPolling()
        payError.value = status === 'expired'
          ? 'O código Pix expirou. Selecione outro método.'
          : 'Pagamento não autorizado. Selecione outro método.'
        payStep.value = 'methods'
        return
      }

      // 'pending' ou estados intermediários → continua pollando
    } catch (err) {
      const httpStatus = err.response?.status

      // Pagamento sumiu do backend
      if (httpStatus === 404) {
        stopPaymentPolling()
        payError.value = 'Pagamento não encontrado.'
        payStep.value = 'methods'
        return
      }

      // Sessão encerrada por fora (cliente pagou por outra via, admin liberou...)
      if (httpStatus === 410) {
        stopPaymentPolling()
        payStep.value = 'done'
        scheduleDone()
        return
      }

      // Token revogado — deixa o interceptor global tratar
      if (httpStatus === 401) {
        stopPaymentPolling()
        return
      }

      // Erros transitórios (5xx, timeout, offline) → ignora e tenta no próximo tick
    }
  }, 5000)
}

function stopPaymentPolling() {
  if (payPollInterval) { clearInterval(payPollInterval); payPollInterval = null }
}

function scheduleDone() {
  setTimeout(() => { payModal.value = false; router.push('/mesas') }, 2500)
}

function closeModal() {
  stopPaymentPolling()
  payModal.value = false
}

onMounted(() => {
  fetchOrders()
  fetchSettings()
  pollInterval = setInterval(fetchOrders, 8000)
})
onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
  stopPaymentPolling()
})
</script>

<template>
  <div class="page">

    <div class="table-topbar">
      <button class="back-btn" @click="router.push('/mesas')">← Voltar</button>
      <div>
        <h2 v-if="table" class="table-topbar-title">Mesa {{ table.number }}</h2>
        <div v-if="clientName()" class="table-topbar-client">{{ clientName() }}</div>
      </div>
    </div>

    <div v-if="loading" class="loading">Carregando pedidos...</div>

    <div v-else-if="orders.length === 0" class="empty-state">
      <div class="empty-state-icon">🍽️</div>
      <p class="empty-state-text">Nenhum pedido ativo nesta mesa.</p>
      <button
        v-if="session"
        class="btn btn-primary btn-add-items"
        @click="router.push('/menu/' + session.id + '?tableId=' + table.id)"
      >
        + Adicionar itens
      </button>
    </div>

    <div v-else>
      <button
        v-if="session"
        class="btn btn-secondary btn-add-items"
        @click="router.push('/menu/' + session.id + '?tableId=' + table.id)"
      >
        + Adicionar itens
      </button>

      <OrderCard
        v-for="order in orders"
        :key="order.id"
        :order="order"
        :action-loading="actionLoading"
        :status-labels="statusLabels"
        :area-labels="areaLabels"
        :print-enabled="printEnabled"
        @approve="approveOrder"
        @reject="rejectOrder"
        @quick-deliver="quickDeliverOrder"
        @deliver="deliverOrder"
        @pick-and-deliver="pickAndDeliverOrder"
        @remove-item="({ orderId, itemId }) => removeItem(orderId, itemId)"
      />

      <BillSummary
        :total="totalGeral()"
        :tip-enabled="tipEnabled"
        :tip-default-percent="tipDefaultPercent"
        @close-bill="openCloseModal"
      />
    </div>

    <!-- ── Modal de Pagamento (lógica completa preservada) ── -->
    <div v-if="payModal" class="pay-overlay" @click.self="closeModal">
      <div class="pay-sheet">

        <div v-if="payLoading" class="pay-loading">
          <span class="btn-spinner pay-spinner"></span>
          Aguarde...
        </div>

        <!-- ENCAMINHAR AO CAIXA -->
        <template v-else-if="payStep === 'forward_cashier'">
          <div class="pay-handle"></div>
          <div class="pay-title">Encaminhar ao Caixa</div>
          <div class="pay-amount">R$ {{ formatPrice(totalGeral()) }}</div>
          <div class="pay-subtitle">Qual será o meio de pagamento?</div>

          <div v-if="forwardError" class="msg msg-error">{{ forwardError }}</div>

          <div class="forward-methods">
            <button
              v-for="m in [
                { key: 'pix',        icon: '📱', label: 'PIX'        },
                { key: 'dinheiro',   icon: '💵', label: 'Dinheiro'   },
                { key: 'cartao',     icon: '💳', label: 'Cartão'     },
                { key: 'maquininha', icon: '🖥️', label: 'Maquininha' },
              ]"
              :key="m.key"
              class="forward-method-btn"
              :class="{ 'forward-method-btn--active': forwardMethod === m.key }"
              :disabled="forwardLoading"
              @click="forwardMethod = m.key; forwardError = ''"
            >
              <span class="forward-method-icon">{{ m.icon }}</span>
              <span>{{ m.label }}</span>
            </button>
          </div>

          <button
            class="btn btn-forward btn-block"
            :disabled="forwardLoading || !forwardMethod"
            @click="confirmForwardCashier"
          >
            <span v-if="forwardLoading" class="btn-spinner"></span>
            {{ forwardLoading ? 'Encaminhando...' : 'Encaminhar ao Caixa →' }}
          </button>

          <button
            class="btn btn-secondary btn-block"
            style="margin-top:8px;"
            :disabled="forwardLoading"
            @click="closeModal"
          >
            Cancelar
          </button>
        </template>

        <!-- ENCAMINHADO COM SUCESSO -->
        <template v-else-if="payStep === 'forwarded'">
          <div class="pay-done">
            <div class="pay-done-check">🏦</div>
            <div class="pay-done-title" style="color: var(--c-teal);">Mesa Encaminhada!</div>
            <div class="pay-done-sub">A mesa foi liberada. O caixa processará o pagamento.</div>
          </div>
        </template>

        <!-- QUEM VAI PAGAR? -->
        <template v-else-if="payStep === 'who_pays'">
          <div class="pay-handle"></div>
          <div class="pay-title">Fechar Conta</div>
          <div v-if="payError" class="msg msg-error">{{ payError }}</div>
          <div class="pay-amount">R$ {{ formatPrice(totalGeral()) }}</div>
          <div class="pay-subtitle">Como o pagamento será realizado?</div>
          <div class="who-pays-options">
            <button class="who-pays-btn" @click="waiterWillPay">
              <span class="who-pays-icon">🧾</span>
              <div class="who-pays-info">
                <div class="who-pays-label">Cobrar aqui</div>
                <div class="who-pays-desc">Processar o pagamento neste dispositivo</div>
              </div>
            </button>
            <button class="who-pays-btn who-pays-client" @click="clientWillPay">
              <span class="who-pays-icon">📱</span>
              <div class="who-pays-info">
                <div class="who-pays-label">Cliente paga no dispositivo dele</div>
                <div class="who-pays-desc">O cliente usará o próprio celular para pagar</div>
              </div>
            </button>
          </div>
          <button class="btn btn-secondary btn-block" style="margin-top:12px;" @click="closeModal">
            Cancelar
          </button>
        </template>

        <!-- CLIENTE PAGA NO DISPOSITIVO DELE -->
        <template v-else-if="payStep === 'client_paying'">
          <div class="pay-handle"></div>
          <div class="pay-title">Aguardando cliente</div>
          <div class="pay-amount">R$ {{ formatPrice(currentPayment?.amount || 0) }}</div>
          <div class="client-paying-box">
            <div class="client-paying-icon">📱</div>
            <p class="client-paying-headline">Conta fechada!</p>
            <p class="client-paying-text">
              O cliente receberá a solicitação no app e efetuará o pagamento no próprio dispositivo.
            </p>
          </div>
          <button class="btn btn-primary" style="margin-top:16px;" @click="closeModal">
            ✓ Entendido — Voltar para mesas
          </button>
        </template>

        <!-- PEDIDOS PENDENTES -->
        <template v-else-if="payStep === 'pending_warning'">
          <div class="pay-handle"></div>
          <div class="pay-title">⚠️ Pedidos em aberto</div>
          <div class="pending-warning-box">
            <p>
              <strong>{{ pendingCount }} pedido{{ pendingCount > 1 ? 's' : '' }}</strong>
              {{ pendingCount > 1 ? 'estão aguardando' : 'está aguardando' }} baixa da cozinha.
            </p>
            <p class="pending-warning-hint">Confirmar a entrega agora e fechar a conta?</p>
          </div>
          <div v-if="payError" class="msg msg-error">{{ payError }}</div>
          <div class="pay-actions-row">
            <button class="btn btn-secondary" style="flex:1" @click="closeModal" :disabled="payLoading">
              Cancelar
            </button>
            <button class="btn btn-success" style="flex:1" @click="confirmForceClose" :disabled="payLoading">
              ✓ Confirmar e fechar
            </button>
          </div>
        </template>

        <!-- SELEÇÃO DE MÉTODO -->
        <template v-else-if="payStep === 'methods'">
          <div class="pay-handle"></div>
          <div class="pay-title">Fechar Conta</div>
          <div v-if="payError" class="msg msg-error">{{ payError }}</div>
          <div class="pay-amount">R$ {{ formatPrice(currentPayment?.amount || totalGeral()) }}</div>
          <div class="pay-subtitle">Selecione o meio de pagamento</div>
          <div class="pay-methods" :class="{ 'has-terminal': terminalEnabled }">
            <button class="pay-method-btn" @click="selectPix"              :disabled="payLoading"><span class="pay-method-icon">📱</span><span>Pix</span></button>
            <button class="pay-method-btn" @click="selectManual('dinheiro')" :disabled="payLoading"><span class="pay-method-icon">💵</span><span>Dinheiro</span></button>
            <button class="pay-method-btn" @click="selectManual('cartao')"   :disabled="payLoading"><span class="pay-method-icon">💳</span><span>Cartão</span></button>
            <button v-if="terminalEnabled" class="pay-method-btn pay-method-terminal" @click="selectTerminal" :disabled="payLoading"><span class="pay-method-icon">🖥️</span><span>Maquininha</span></button>
          </div>
          <button class="btn btn-secondary btn-block" style="margin-top:12px;" @click="closeModal">Cancelar</button>
        </template>

        <!-- PIX -->
        <template v-else-if="payStep === 'pix'">
          <div class="pay-handle"></div>
          <div class="pay-title">Pix — Aguardando pagamento</div>
          <div class="pay-amount">R$ {{ formatPrice(currentPayment.amount) }}</div>
          <div v-if="currentPayment.qr_code_base64" class="qr-wrapper">
            <img :src="'data:image/png;base64,' + currentPayment.qr_code_base64" class="qr-image" alt="QR Pix" />
          </div>
          <div v-if="currentPayment.qr_code" class="copy-area">
            <div class="copy-label">Pix Copia e Cola</div>
            <div class="copy-code">{{ currentPayment.qr_code.slice(0, 60) }}...</div>
            <button class="btn btn-primary btn-sm btn-block" @click="copyPixCode">📋 Copiar código</button>
          </div>
          <div class="pay-polling">
            <span class="polling-dot"></span> Aguardando confirmação...
          </div>
          <button class="btn btn-secondary btn-block" style="margin-top:12px;" @click="closeModal">Cancelar</button>
        </template>

        <!-- MAQUININHA -->
        <template v-else-if="payStep === 'terminal_waiting'">
          <div class="pay-handle"></div>
          <div class="pay-title">Maquininha acionada</div>
          <div class="pay-amount">R$ {{ formatPrice(currentPayment.amount) }}</div>
          <div class="terminal-waiting">
            <div class="terminal-icon">🖥️</div>
            <p>Passe o cartão no terminal.</p>
          </div>
        </template>

        <!-- CONFIRMADO -->
        <template v-else-if="payStep === 'done'">
          <div class="pay-done">
            <div class="pay-done-check">✅</div>
            <div class="pay-done-title">Pagamento Confirmado!</div>
            <div class="pay-done-sub">Mesa liberada. Redirecionando...</div>
          </div>
        </template>

      </div>
    </div>

  </div>
</template>

<style scoped>
/* ── Topbar com "Voltar" + título ── */
.table-topbar {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
}
.back-btn {
  background: var(--surface);
  border: 1px solid var(--border-default);
  color: var(--text-secondary);
  padding: 8px 14px;
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-family: inherit;
  transition: background var(--dur-fast), border-color var(--dur-fast);
}
.back-btn:hover { background: var(--c-gray-50); border-color: var(--brand-accent); }
.table-topbar-title {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin: 0;
}
.table-topbar-client {
  font-size: var(--fs-xs);
  color: var(--text-muted);
  font-weight: var(--fw-semibold);
  margin-top: 1px;
}

/* ── Botão adicionar itens ── */
.btn-add-items {
  display: block;
  width: 100%;
  margin-bottom: 12px;
  text-align: center;
}

/* ── Header do card (lado esquerdo) ── */
.order-head-left {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.order-user {
  font-size: var(--fs-xs);
  color: var(--text-muted);
}

/* ── Agrupamento por área (cozinha/bar/lanchonete) ── */
.order-area-group { margin-bottom: 12px; }
.order-area-group:last-child { margin-bottom: 0; }

/* ── Linhas de itens ── */
.order-item-row {
  gap: 8px;
  justify-content: space-between;
  align-items: flex-start;
}
.order-item-info {
  flex: 1;
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 6px;
  align-items: baseline;
}
.order-item-qty {
  font-weight: var(--fw-bold);
  color: var(--brand-primary);
  font-variant-numeric: tabular-nums;
}
.order-item-name {
  color: var(--text-primary);
}
.order-item-price {
  font-size: var(--fs-xs);
  color: var(--text-muted);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.order-item-notes {
  grid-column: 1 / -1;
  font-size: var(--fs-xs);
  color: var(--text-muted);
  font-style: italic;
  margin-top: 2px;
}
.remove-item-btn {
  background: none;
  border: none;
  color: var(--status-danger-color);
  cursor: pointer;
  font-size: var(--fs-lg);
  padding: 2px 6px;
  line-height: 1;
  border-radius: var(--radius-xs);
  transition: background var(--dur-fast);
  flex-shrink: 0;
}
.remove-item-btn:hover { background: var(--status-pending-bg); }

/* ── Card de TOTAL GERAL (gradiente verde folha) ── */
.total-geral-card {
  background: linear-gradient(135deg, var(--c-leaf-700) 0%, var(--c-leaf-900) 100%);
  color: #FFFFFF;
  border-radius: var(--radius);
  padding: 16px 18px;
  margin-top: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  box-shadow: 0 4px 12px rgba(50, 104, 13, 0.25);
}
.total-geral-label {
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
}
.total-geral-value {
  font-size: 26px;
  font-weight: var(--fw-black);
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.5px;
}

/* ── Fechar Conta (CTA laranja) ── */
.btn-close-bill {
  width: 100%;
  margin-top: 12px;
}

/* ================================================
   MODAL DE PAGAMENTO
================================================ */
.pay-overlay {
  position: fixed;
  inset: 0;
  background: rgba(31, 30, 25, 0.55);
  display: flex;
  align-items: flex-end;
  justify-content: center;
  z-index: 1000;
}
.pay-sheet {
  background: var(--surface);
  border-radius: 20px 20px 0 0;
  padding: 0 20px 28px;
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  overflow-y: auto;
  box-shadow: 0 -4px 24px rgba(31, 30, 25, 0.18);
}
.pay-handle {
  width: 36px;
  height: 4px;
  background: var(--border-default);
  border-radius: 2px;
  margin: 10px auto 18px;
}
.pay-title {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  text-align: center;
  margin-bottom: 8px;
}
.pay-amount {
  font-size: 34px;
  font-weight: var(--fw-black);
  color: var(--brand-primary);
  text-align: center;
  margin-bottom: 4px;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.5px;
}
.pay-subtitle {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  text-align: center;
  margin-bottom: 20px;
}
.pay-loading {
  text-align: center;
  padding: 40px 20px;
  color: var(--text-muted);
  font-size: var(--fs-md);
}

/* ── Quem vai pagar ── */
.who-pays-options {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.who-pays-btn {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px;
  border-radius: var(--radius);
  border: 2px solid var(--border-default);
  background: var(--surface-sunken);
  color: var(--text-primary);
  cursor: pointer;
  font-family: inherit;
  text-align: left;
  transition: border-color var(--dur-fast), background var(--dur-fast);
  width: 100%;
}
.who-pays-btn:hover {
  border-color: var(--brand-accent);
  background: var(--brand-accent-soft);
}
.who-pays-btn.who-pays-client:hover {
  border-color: var(--brand-primary);
  background: var(--brand-primary-soft);
}
.who-pays-icon {
  font-size: 28px;
  flex-shrink: 0;
}
.who-pays-label {
  font-size: var(--fs-md);
  font-weight: var(--fw-semibold);
  color: var(--text-primary);
}
.who-pays-desc {
  font-size: var(--fs-xs);
  color: var(--text-muted);
  margin-top: 2px;
}

/* ── Cliente pagando (caixa teal claro) ── */
.client-paying-box {
  background: var(--status-ready-bg);
  border: 1px solid var(--status-ready-border);
  border-radius: var(--radius);
  padding: 24px;
  text-align: center;
  margin-top: 12px;
}
.client-paying-icon { font-size: 48px; margin-bottom: 12px; line-height: 1; }
.client-paying-headline {
  font-size: var(--fs-md);
  font-weight: var(--fw-semibold);
  color: var(--status-ready-color);
  margin-bottom: 6px;
}
.client-paying-text {
  font-size: var(--fs-sm);
  color: var(--text-secondary);
}

/* ── Pedidos pendentes (caixa âmbar) ── */
.pending-warning-box {
  background: var(--status-received-bg);
  border: 1px solid var(--status-received-border);
  border-radius: var(--radius-sm);
  padding: 14px 16px;
  font-size: var(--fs-md);
  color: var(--text-primary);
  margin-bottom: 12px;
}
.pending-warning-hint {
  margin-top: 8px;
  font-size: var(--fs-sm);
  color: var(--text-secondary);
}

.pay-actions-row {
  display: flex;
  gap: 10px;
  margin-top: 4px;
}

/* ── Grid de métodos ── */
.pay-methods {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}
.pay-methods.has-terminal {
  grid-template-columns: repeat(2, 1fr);
}
.pay-method-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 16px 8px;
  border-radius: var(--radius);
  border: 2px solid var(--border-default);
  background: var(--surface-sunken);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  font-family: inherit;
  transition: border-color var(--dur-fast), background var(--dur-fast), color var(--dur-fast);
}
.pay-method-btn:hover:not(:disabled) {
  border-color: var(--brand-accent);
  background: var(--brand-accent-soft);
  color: var(--brand-accent);
}
.pay-method-btn.pay-method-terminal:hover:not(:disabled) {
  border-color: var(--status-info-color);
  background: rgba(69, 123, 157, 0.10);
  color: var(--status-info-color);
}
.pay-method-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.pay-method-icon { font-size: 26px; line-height: 1; }

/* ── QR Pix ── */
.qr-wrapper {
  display: flex;
  justify-content: center;
  margin: 16px 0;
}
.qr-image {
  width: 220px;
  height: 220px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border-default);
  padding: 8px;
  background: #FFFFFF;
}
.copy-area {
  background: var(--surface-sunken);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-sm);
  padding: 12px;
  margin-bottom: 16px;
}
.copy-label {
  font-size: var(--fs-xs);
  color: var(--text-muted);
  margin-bottom: 4px;
}
.copy-code {
  font-size: var(--fs-xs);
  word-break: break-all;
  color: var(--text-secondary);
  margin-bottom: 10px;
  font-family: monospace;
}

.pay-polling {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: var(--text-muted);
  font-size: var(--fs-sm);
  padding: 6px 0;
}
.polling-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--status-success-color);
  animation: pulse-dot 1.5s ease-in-out infinite;
}
@keyframes pulse-dot {
  0%, 100% { opacity: 1; }
  50%      { opacity: 0.3; }
}

/* ── Maquininha ── */
.terminal-waiting {
  text-align: center;
  padding: 24px 0;
}
.terminal-icon { font-size: 48px; margin-bottom: 12px; line-height: 1; }
.terminal-waiting p { color: var(--text-muted); font-size: var(--fs-md); }

/* ── Done ── */
.pay-done {
  text-align: center;
  padding: 24px 0;
}
.pay-done-check {
  font-size: 56px;
  margin-bottom: 12px;
  line-height: 1;
}
.pay-done-title {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: var(--status-success-color);
}
.pay-done-sub {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  margin-top: 6px;
}
/* ── Encaminhar ao Caixa ── */
.forward-methods {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 16px;
}
.forward-method-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 16px 8px;
  border-radius: var(--radius);
  border: 2px solid var(--border-default);
  background: var(--surface-sunken);
  color: var(--text-secondary);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  font-family: inherit;
  transition: border-color var(--dur-fast), background var(--dur-fast), color var(--dur-fast);
}
.forward-method-btn:hover:not(:disabled) {
  border-color: var(--c-teal);
  background: rgba(42, 157, 143, 0.08);
  color: var(--c-teal);
}
.forward-method-btn--active {
  border-color: var(--c-teal);
  background: rgba(42, 157, 143, 0.10);
  color: var(--c-teal);
}
.forward-method-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.forward-method-icon { font-size: 26px; line-height: 1; }

.btn-forward {
  background: var(--c-teal);
  color: #FFFFFF;
  border: none;
  padding: 14px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  font-family: inherit;
  cursor: pointer;
  transition: background var(--dur-fast);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}
.btn-forward:hover:not(:disabled) { background: #1F7A6F; }
.btn-forward:disabled { opacity: 0.5; cursor: not-allowed; }

/* Spinner no modal de pagamento */
.pay-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 40px 20px;
  color: var(--text-muted);
  font-size: var(--fs-md);
}
.pay-spinner {
  width: 20px;
  height: 20px;
  border-width: 2px;
}
</style>

