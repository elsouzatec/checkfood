<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import api from '../api'

const orders = ref([])
const loading = ref(true)
const actionLoading = ref(null)
const dismissedIds = ref([])
const tooltipId = ref(null)
const tooltipPos = ref({ x: 0, y: 0 })

// Estado de confirmação em 2 toques
const confirmingId = ref(null)
let confirmTimeout = null
const CONFIRM_TIMEOUT_MS = 3000

let pollInterval = null

const area = 'cozinha'

const fila       = computed(() => orders.value.filter(o => o.status === 'confirmado'))
const preparando = computed(() => orders.value.filter(o => o.status === 'preparando'))
const pronto     = computed(() => orders.value.filter(o => o.status === 'pronto'))
const entregues  = computed(() =>
  orders.value.filter(o => o.status === 'entregue' && !dismissedIds.value.includes(o.id))
)

const tooltipOrder = computed(() => {
  if (!tooltipId.value) return null
  return entregues.value.find(o => o.id === tooltipId.value)
})

async function fetchOrders() {
  try {
    const { data } = await api.get(`/orders/kitchen?area=${area}`)
    orders.value = data.orders
  } catch (err) {
    console.error('Erro:', err)
  } finally {
    loading.value = false
  }
}

async function startOrder(orderId) {
  actionLoading.value = `start-${orderId}`
  try {
    await api.patch(`/orders/${orderId}/start`)
    await fetchOrders()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro')
  } finally {
    actionLoading.value = null
  }
}

async function readyOrder(orderId) {
  actionLoading.value = `ready-${orderId}`
  try {
    await api.patch(`/orders/${orderId}/ready`)
    await fetchOrders()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro')
  } finally {
    actionLoading.value = null
  }
}

/**
 * 2-tap action:
 *   Primeiro toque → arma o card (estado "confirming"); auto-reset em 3s
 *   Segundo toque no mesmo card → executa a ação
 *   Toque em outro card → troca o armado
 *   Cards em "pronto" não são interativos (aguardam garçom)
 */
function handleCardClick(order) {
  if (order.status === 'pronto') return
  if (actionLoading.value) return

  const alreadyConfirming = confirmingId.value === order.id

  if (alreadyConfirming) {
    // Segundo toque — executa
    clearConfirmTimeout()
    confirmingId.value = null

    if (order.status === 'confirmado') {
      startOrder(order.id)
    } else if (order.status === 'preparando') {
      readyOrder(order.id)
    }
  } else {
    // Primeiro toque — arma
    confirmingId.value = order.id
    clearConfirmTimeout()
    confirmTimeout = setTimeout(() => {
      if (confirmingId.value === order.id) {
        confirmingId.value = null
      }
    }, CONFIRM_TIMEOUT_MS)
  }
}

function isUrgent(dateStr, thresholdMin = 15) {
  if (!dateStr) return false
  return Math.floor((Date.now() - new Date(dateStr).getTime()) / 60000) >= thresholdMin
}

function clearConfirmTimeout() {
  if (confirmTimeout) {
    clearTimeout(confirmTimeout)
    confirmTimeout = null
  }
}

function confirmHintFor(status) {
  if (status === 'confirmado') return '🔥 Tocar novamente para INICIAR PREPARO'
  if (status === 'preparando') return '✅ Tocar novamente para MARCAR PRONTO'
  return ''
}

function clearEntregues() {
  dismissedIds.value.push(...entregues.value.map(o => o.id))
  tooltipId.value = null
}

function toggleTooltip(orderId, event) {
  if (tooltipId.value === orderId) {
    tooltipId.value = null
    return
  }
  const rect = event.currentTarget.getBoundingClientRect()
  tooltipPos.value = {
    x: rect.left + rect.width / 2,
    y: rect.top - 8,
  }
  tooltipId.value = orderId
}

function closeTooltip() {
  tooltipId.value = null
}

function timeSince(dateStr) {
  if (!dateStr) return '0'
  const diff = Math.floor((Date.now() - new Date(dateStr).getTime()) / 60000)
  if (diff < 1) return '0'
  return `${diff}`
}

function truncate(text, max) {
  if (!text) return ''
  return text.length > max ? text.substring(0, max) + '...' : text
}

function entregueLabel(order) {
  const item = order.items[0]
  const name = truncate(item?.product?.name || '', 14)
  const extra = order.items.length > 1 ? ` +${order.items.length - 1}` : ''
  return name + extra
}

onMounted(() => {
  fetchOrders()
  pollInterval = setInterval(fetchOrders, 5000)
})

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
  clearConfirmTimeout()
})
</script>

<template>
  <div v-if="loading" class="kds-loading">
    Carregando pedidos...
  </div>

  <div v-else>
    <div class="kds-container">
      <!-- ── Coluna: FILA ── -->
      <div class="kds-column col-fila">
        <div class="kds-column-title">Fila ({{ fila.length }})</div>
        <div v-if="fila.length === 0" class="kds-empty">Nenhum pedido na fila</div>

        <div
          v-for="order in fila"
          :key="order.id"
          class="kds-card"
          :class="{
            'kds-card-confirming': confirmingId === order.id,
            'kds-card-loading': actionLoading === `start-${order.id}`,
          }"
          @click="handleCardClick(order)"
        >
          <div class="kds-card-header">
            <span class="kds-order-id">#{{ order.id }}</span>
            <span v-if="order.table" class="kds-table">Mesa {{ order.table.number }}</span>
            <span v-else-if="order.origin === 'delivery'" class="kds-table">🛵 Entrega</span>
            <span class="kds-time" :class="{ urgent: isUrgent(order.confirmed_at) }">{{ timeSince(order.confirmed_at) }}min</span>
          </div>

          <ul class="kds-items">
            <li v-for="item in order.items" :key="item.id">
              <strong>{{ item.quantity }}x</strong> {{ item.product.name }}
              <div v-if="item.notes" class="kds-item-notes">⚠ {{ item.notes }}</div>
            </li>
          </ul>

          <div v-if="confirmingId === order.id" class="kds-confirm-hint">
            {{ confirmHintFor(order.status) }}
          </div>
        </div>
      </div>

      <!-- ── Coluna: PREPARANDO ── -->
      <div class="kds-column col-preparando">
        <div class="kds-column-title">Preparando ({{ preparando.length }})</div>
        <div v-if="preparando.length === 0" class="kds-empty">Nenhum em preparo</div>

        <div
          v-for="order in preparando"
          :key="order.id"
          class="kds-card"
          :class="{
            'kds-card-confirming': confirmingId === order.id,
            'kds-card-loading': actionLoading === `ready-${order.id}`,
          }"
          @click="handleCardClick(order)"
        >
          <div class="kds-card-header">
            <span class="kds-order-id">#{{ order.id }}</span>
            <span v-if="order.table" class="kds-table">Mesa {{ order.table.number }}</span>
            <span v-else-if="order.origin === 'delivery'" class="kds-table">🛵 Entrega</span>
            <span class="kds-time" :class="{ urgent: isUrgent(order.kitchen_started_at) }">{{ timeSince(order.kitchen_started_at) }}min</span>
          </div>

          <ul class="kds-items">
            <li v-for="item in order.items" :key="item.id">
              <strong>{{ item.quantity }}x</strong> {{ item.product.name }}
              <div v-if="item.notes" class="kds-item-notes">⚠ {{ item.notes }}</div>
            </li>
          </ul>

          <div v-if="confirmingId === order.id" class="kds-confirm-hint">
            {{ confirmHintFor(order.status) }}
          </div>
        </div>
      </div>

      <!-- ── Coluna: PRONTO (não interativa) ── -->
      <div class="kds-column col-pronto" :class="{ 'col-has-ready': pronto.length > 0 }">
        <div class="kds-column-title">
          Pronto
          <span v-if="pronto.length > 0" class="pronto-count">{{ pronto.length }}</span>
        </div>
        <div v-if="pronto.length === 0" class="kds-empty">Nenhum pronto</div>

        <div
          v-for="order in pronto"
          :key="order.id"
          class="kds-card kds-card-done"
        >
          <div class="kds-card-header">
            <span class="kds-order-id">#{{ order.id }}</span>
            <span v-if="order.table" class="kds-table">Mesa {{ order.table.number }}</span>
            <span v-else-if="order.origin === 'delivery'" class="kds-table">🛵 Entrega</span>
            <span class="kds-time">{{ timeSince(order.ready_at) }}min</span>
          </div>

          <ul class="kds-items">
            <li v-for="item in order.items" :key="item.id">
              <strong>{{ item.quantity }}x</strong> {{ item.product.name }}
            </li>
          </ul>

          <div class="kds-waiting">Aguardando garçom</div>
        </div>
      </div>
    </div>

    <!-- ── Seção ENTREGUES ── -->
    <div v-if="entregues.length > 0" class="entregues-section">
      <div class="entregues-header">
        <span class="entregues-title">Entregues ({{ entregues.length }})</span>
        <button class="entregues-clear-btn" @click="clearEntregues">Limpar</button>
      </div>

      <div class="entregues-chips">
        <div
          v-for="order in entregues"
          :key="order.id"
          class="entregue-chip"
          :class="{ active: tooltipId === order.id }"
          @click="toggleTooltip(order.id, $event)"
        >
          <span class="chip-order">#{{ order.id }}</span>
          <span class="chip-mesa">{{ order.table ? 'M' + order.table.number : '🛵' }}</span>
          <span class="chip-name">{{ entregueLabel(order) }}</span>
          <span class="chip-time">{{ timeSince(order.delivered_at) }}'</span>
        </div>
      </div>
    </div>

    <!-- ── Tooltip dos entregues ── -->
    <div v-if="tooltipOrder" class="tooltip-overlay" @click="closeTooltip">
      <div
        class="tooltip-box"
        :style="{ left: tooltipPos.x + 'px', top: tooltipPos.y + 'px' }"
        @click.stop
      >
        <div class="tooltip-header">
          <span>Pedido #{{ tooltipOrder.id }}</span>
          <span v-if="tooltipOrder.table">Mesa {{ tooltipOrder.table.number }}</span>
          <span v-else-if="tooltipOrder.origin === 'delivery'">🛵 Entrega</span>
        </div>
        <div v-for="item in tooltipOrder.items" :key="item.id" class="tooltip-item">
          <span>{{ item.quantity }}x {{ item.product.name }}</span>
          <div v-if="item.notes" class="tooltip-note">⚠ {{ item.notes }}</div>
        </div>
        <div class="tooltip-close" @click="closeTooltip">Fechar</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Estes são exclusivos desta view.
   Tudo mais (cards, colunas, chips, tooltip, etc.) vive no style.css global. */

.kds-loading {
  text-align: center;
  padding: 60px;
  color: var(--kds-text-muted);
  font-size: var(--fs-md);
}

.entregues-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}
</style>
