<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

var router = useRouter()
var orders = ref([])
var loading = ref(true)
var showProgress = ref(true)
var pollInterval = null

var progressSteps = [
  { key: 'pendente_validacao', label: 'Pedindo' },
  { key: 'confirmado', label: 'Na fila' },
  { key: 'preparando', label: 'Preparando' },
  { key: 'pronto', label: 'Pronto' },
]

var statusIndex = {
  pendente_validacao: 0,
  confirmado: 1,
  preparando: 2,
  pronto: 3,
}

var statusLabels = {
  pendente_validacao: 'Aguardando',
  confirmado: 'Na fila',
  preparando: 'Preparando',
  pronto: 'Pronto!',
  entregue: 'Entregue',
  pago: 'Pago',
  cancelado: 'Cancelado',
}

var sortedOrders = computed(function() {
  var active = []
  var done = []
  var cancelled = []
  for (var i = 0; i < orders.value.length; i++) {
    var o = orders.value[i]
    if (o.status === 'cancelado') cancelled.push(o)
    else if (o.status === 'entregue' || o.status === 'pago') done.push(o)
    else active.push(o)
  }
  return active.concat(done).concat(cancelled)
})

var sessionTotal = computed(function() {
  var total = 0
  for (var i = 0; i < orders.value.length; i++) {
    if (orders.value[i].status === 'cancelado') continue
    total += orderTotal(orders.value[i])
  }
  return total
})

function orderTotal(order) {
  var total = 0
  for (var i = 0; i < order.items.length; i++) {
    total += parseFloat(order.items[i].unit_price) * order.items[i].quantity
  }
  return total
}

function getStepIndex(status) {
  var idx = statusIndex[status]
  return idx !== undefined ? idx : -1
}

function isActive(order) {
  return statusIndex[order.status] !== undefined
}

function formatTime(dt) {
  if (!dt) return ''
  return new Date(dt).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

function formatPrice(value) {
  return parseFloat(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

async function fetchOrders() {
  try {
    var res = await api.get('/orders/my-session')
    orders.value = res.data.orders || []
  } catch (err) {
    // ── CORREÇÃO BUG 2 ──────────────────────────────────────────────────
    // 410 = sessão encerrada pelo garçom enquanto o cliente navegava.
    // Antes, o catch era genérico: a lista ficava vazia ("tela limpa")
    // sem nenhum redirect para o feedback.
    if (err.response?.status === 410) {
      try {
        var stored = localStorage.getItem('pf_session')
        if (stored) {
          var parsed = JSON.parse(stored)
          localStorage.setItem('pf_feedback_session', String(parsed.id || ''))
        }
      } catch (e) {}

      // Manter token — FeedbackView precisa dele para o POST /feedbacks
      localStorage.removeItem('pf_user')
      localStorage.removeItem('pf_session')
      localStorage.removeItem('pf_table')
      localStorage.removeItem('pf_orders')

      if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
      router.replace('/feedback')
      return
    }
    console.error('Erro ao buscar pedidos:', err)
  } finally {
    loading.value = false
  }
}

onMounted(function() {
  fetchOrders()
  pollInterval = setInterval(fetchOrders, 8000)
  var setting = localStorage.getItem('pf_show_progress')
  showProgress.value = setting !== 'false'
})

onUnmounted(function() {
  if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
  <div class="ot-page">
    <h2 class="ot-title">Meus Pedidos</h2>

    <div v-if="loading" class="loading">Carregando pedidos...</div>

    <div v-else-if="!sortedOrders.length" class="empty-state">
      <div class="empty-state-icon">📋</div>
      <p class="empty-state-text">Nenhum pedido ainda</p>
      <button class="btn btn-primary btn-sm" @click="router.push('/cardapio')">Ver Cardápio</button>
    </div>

    <div v-else class="ot-list">
      <div
        v-for="order in sortedOrders"
        :key="order.id"
        class="ot-card"
        :class="{ 'ot-card-done': !isActive(order), 'ot-card-cancelled': order.status === 'cancelado' }"
      >
        <!-- Header -->
        <div class="ot-card-header">
          <div class="ot-order-info">
            <span class="ot-order-id">#{{ order.id }}</span>
            <span class="ot-order-time">{{ formatTime(order.created_at) }}</span>
          </div>
          <span class="badge" :class="'badge-status-' + order.status">
            {{ statusLabels[order.status] || order.status }}
          </span>
        </div>

        <!-- Items -->
        <div class="ot-items">
          <div v-for="item in order.items" :key="item.id" class="ot-item">
            <span class="ot-item-qty">{{ item.quantity }}×</span>
            <span class="ot-item-name">{{ item.product.name }}</span>
            <span class="ot-item-price">R$ {{ formatPrice(parseFloat(item.unit_price) * item.quantity) }}</span>
          </div>
        </div>

        <!-- Progress inline -->
        <div v-if="showProgress && isActive(order)" class="ot-progress">
          <div class="ot-progress-track">
            <div class="ot-progress-fill" :style="{ width: ((getStepIndex(order.status)) / 3 * 100) + '%' }"></div>
          </div>
          <div class="ot-progress-labels">
            <span
              v-for="(step, i) in progressSteps"
              :key="step.key"
              class="ot-progress-label"
              :class="{ 'ot-label-done': i <= getStepIndex(order.status) }"
            >
              <span
                class="ot-dot"
                :class="{ 'ot-dot-done': i <= getStepIndex(order.status), 'ot-dot-current': i === getStepIndex(order.status) }"
              ></span>
              {{ step.label }}
            </span>
          </div>
        </div>

        <!-- Delivered badge -->
        <div v-else-if="order.status === 'entregue'" class="ot-delivered-badge">
          ✓ Entregue
        </div>
      </div>

      <!-- Session total -->
      <div class="ot-total">
        <span class="ot-total-label">Total da sessão</span>
        <span class="ot-total-value">R$ {{ formatPrice(sessionTotal) }}</span>
      </div>

      <button class="ot-btn ot-btn-new" @click="router.push('/cardapio')">
        + Fazer Novo Pedido
      </button>
    </div>
  </div>
</template>

<style scoped>
.ot-page {
  min-height: 100vh;
  background: var(--page-bg);
  padding: 16px 16px 120px;
  max-width: 600px;
  margin: 0 auto;
}

.ot-title {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin-bottom: 16px;
  padding: 0 4px;
}

/* ── Loading / Empty ── */
.ot-loading {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  padding: 60px 20px;
  color: var(--text-muted);
  font-size: var(--fs-md);
}
.ot-spinner {
  width: 28px;
  height: 28px;
  border: 3px solid var(--border-subtle);
  border-top-color: var(--brand-accent);
  border-radius: 50%;
  animation: ot-spin 0.8s linear infinite;
}
@keyframes ot-spin { to { transform: rotate(360deg); } }

.ot-empty {
  text-align: center;
  padding: 60px 20px;
  color: var(--text-muted);
}
.ot-empty-icon { font-size: 48px; margin-bottom: 12px; }
.ot-empty p {
  font-size: var(--fs-lg);
  margin-bottom: 20px;
  color: var(--text-secondary);
}

/* ── Lista ── */
.ot-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.ot-card {
  background: var(--surface);
  border-radius: var(--radius);
  padding: 14px 16px;
  box-shadow: var(--shadow-sm);
  border: 1px solid var(--border-subtle);
}
.ot-card-done      { opacity: 0.65; }
.ot-card-cancelled { opacity: 0.45; }

.ot-card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}
.ot-order-info {
  display: flex;
  align-items: center;
  gap: 8px;
}
.ot-order-id   { font-size: var(--fs-md); font-weight: var(--fw-bold); color: var(--text-primary); }
.ot-order-time { font-size: var(--fs-xs); color: var(--text-muted); }
.ot-status     { font-size: 12px; font-weight: var(--fw-bold); }

/* ── Items ── */
.ot-items {
  margin-bottom: 10px;
}
.ot-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 0;
  font-size: var(--fs-sm);
  color: var(--text-secondary);
  border-bottom: 1px dashed var(--border-subtle);
}
.ot-item:last-child { border-bottom: none; }
.ot-item-qty {
  font-weight: var(--fw-bold);
  color: var(--brand-primary);
  min-width: 28px;
}
.ot-item-name { flex: 1; }
.ot-item-price {
  font-size: 12px;
  color: var(--text-muted);
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

/* ── Progress bar (inline, sutil) ── */
.ot-progress {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--border-subtle);
}
.ot-progress-track {
  height: 4px;
  background: var(--c-gray-100);
  border-radius: 2px;
  position: relative;
  margin-bottom: 10px;
  overflow: hidden;
}
.ot-progress-fill {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  background: linear-gradient(90deg,
    var(--status-pending-color),
    var(--status-received-color),
    var(--status-preparing-color),
    var(--status-ready-color)
  );
  border-radius: 2px;
  transition: width 0.5s ease;
}
.ot-progress-labels {
  display: flex;
  justify-content: space-between;
}
.ot-progress-label {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 3px;
  font-size: 10px;
  font-weight: var(--fw-semibold);
  color: var(--text-muted);
  flex: 1;
}
.ot-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--border-default);
  transition: all var(--dur-normal) var(--ease-out);
}
.ot-dot-done { background: var(--brand-accent); }
.ot-label-done { color: var(--brand-accent); }
.ot-dot-current {
  width: 11px;
  height: 11px;
  box-shadow: 0 0 0 3px rgba(221, 111, 45, 0.18);
}

/* ── Entregue ── */
.ot-delivered-badge {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--border-subtle);
  font-size: var(--fs-xs);
  font-weight: var(--fw-bold);
  color: var(--status-ready-color);
  text-align: center;
}

/* ── Sumário ── */
.ot-total {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  padding: 16px;
  background: var(--surface);
  border-radius: var(--radius);
  border: 1px solid var(--border-subtle);
  box-shadow: var(--shadow-sm);
  margin-top: 6px;
}
.ot-total-label {
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  color: var(--text-secondary);
}
.ot-total-value {
  font-size: 20px;
  font-weight: var(--fw-black);
  color: var(--brand-primary);
  font-variant-numeric: tabular-nums;
}

/* ── Botões ── */
.ot-btn {
  width: 100%;
  padding: 14px;
  background: var(--brand-accent);
  color: #FFFFFF;
  border: none;
  border-radius: var(--radius);
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  cursor: pointer;
  font-family: inherit;
  touch-action: manipulation;
  transition: background var(--dur-normal), transform var(--dur-fast);
  box-shadow: 0 4px 12px rgba(221, 111, 45, 0.22);
}
.ot-btn:active { background: var(--brand-accent-hover); transform: scale(0.98); }

.ot-btn-new {
  margin-top: 12px;
}
</style>
