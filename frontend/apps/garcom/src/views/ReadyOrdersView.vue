<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import api from '../api'

const orders = ref([])
const loading = ref(true)
const actionLoading = ref(null)
let pollInterval = null

async function fetchReady() {
  try {
    const { data } = await api.get('/orders/ready')
    orders.value = data.orders
  } catch (err) {
    console.error('Erro:', err)
  } finally {
    loading.value = false
  }
}

async function deliverOrder(orderId) {
  actionLoading.value = orderId
  try {
    await api.patch(`/orders/${orderId}/deliver`)
    await fetchReady()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao confirmar entrega')
  } finally {
    actionLoading.value = null
  }
}

function orderTotal(order) {
  return order.items.reduce((sum, item) => sum + parseFloat(item.unit_price) * item.quantity, 0)
}

function formatPrice(value) {
  return parseFloat(value).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

onMounted(() => {
  fetchReady()
  pollInterval = setInterval(fetchReady, 5000)
})

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
  <div class="page">
    <h2 class="section-title">Prontos para Entrega</h2>

    <div v-if="loading" class="loading">Carregando...</div>

    <div v-else-if="orders.length === 0" class="empty-state">
      <div class="empty-icon">✅</div>
      <p>Nenhum pedido pronto no momento.</p>
    </div>

    <div v-else>
      <div
        v-for="order in orders"
        :key="order.id"
        class="order-card ready-order-card"
      >
        <div class="order-header">
          <div class="order-head-left">
            <span class="order-id">Pedido #{{ order.id }}</span>
            <span v-if="order.table" class="order-table">Mesa {{ order.table.number }}</span>
          </div>
          <span class="status-badge status-pronto">Pronto</span>
        </div>

        <ul class="order-items-list">
          <li v-for="item in order.items" :key="item.id">
            <span class="ready-item-qty">{{ item.quantity }}×</span>
            <span class="ready-item-name">{{ item.product.name }}</span>
            <span v-if="item.notes" class="ready-item-notes">({{ item.notes }})</span>
          </li>
        </ul>

        <div class="order-total">
          Total: R$ {{ formatPrice(orderTotal(order)) }}
        </div>

        <button
          class="btn btn-primary"
          style="margin-top: 12px;"
          :disabled="actionLoading === order.id"
          @click="deliverOrder(order.id)"
        >
          {{ actionLoading === order.id ? 'Confirmando...' : '🍽 Confirmar Entrega' }}
        </button>
      </div>
    </div>
  </div>
</template>

<style scoped>
.empty-icon {
  font-size: 48px;
  margin-bottom: 16px;
  line-height: 1;
}

/* Card de pronto ganha um acento teal à esquerda — sinaliza "pronto" sem roubar a cena. */
.ready-order-card {
  border-left: 4px solid var(--status-ready-color);
}

.order-head-left {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.order-table {
  font-size: var(--fs-xs);
  color: var(--text-muted);
  font-weight: var(--fw-semibold);
}

.ready-item-qty {
  font-weight: var(--fw-bold);
  color: var(--brand-primary);
  min-width: 24px;
  font-variant-numeric: tabular-nums;
}
.ready-item-name { color: var(--text-primary); flex: 1; }
.ready-item-notes {
  font-size: var(--fs-xs);
  color: var(--text-muted);
  font-style: italic;
}
</style>
