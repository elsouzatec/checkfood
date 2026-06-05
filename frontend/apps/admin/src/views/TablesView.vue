<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router        = useRouter()
const tables        = ref([])
const loading       = ref(true)
const releasing     = ref(null)
const detailModal   = ref(null)
const detailLoading = ref(false)
let pollInterval  = null

const orderStatusLabels = {
  pendente_validacao: 'Pendente',
  confirmado:         'Em fila',
  preparando:         'Preparando',
  pronto:             'Pronto',
  entregue:           'Entregue',
  pago:               'Pago',
  cancelado:          'Cancelado',
}

// Classe de badge por status — sem mais hex hard-coded.
const orderStatusBadgeClass = {
  pendente_validacao: 'badge-danger',
  confirmado:         'badge-yellow',
  preparando:         'badge-accent',
  pronto:             'badge-active',
  entregue:           'badge-info',
  pago:               'badge-inactive',
  cancelado:          'badge-inactive',
}

function getTablePriority(t) {
  if (t.pending_count > 0)    return 'priority-pending'
  if (t.ready_count > 0)      return 'priority-ready'
  if (t.preparing_count > 0)  return 'priority-preparing'
  if (t.received_count > 0)   return 'priority-received'
  if (t.delivered_count > 0)  return 'priority-delivered'
  if (t.status === 'ocupada') return 'priority-occupied'
  return 'priority-free'
}

function getStatusLabel(t) {
  if (t.pending_count > 0)    return t.pending_count + ' pendente' + (t.pending_count > 1 ? 's' : '')
  if (t.ready_count > 0)      return t.ready_count + ' pronto' + (t.ready_count > 1 ? 's' : '')
  if (t.preparing_count > 0)  return 'Preparando'
  if (t.received_count > 0)   return 'Em fila'
  if (t.delivered_count > 0)  return 'Servida'
  if (t.status === 'ocupada') return 'Ocupada'
  return 'Livre'
}

function formatMoney(v) {
  return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatDuration(startedAt) {
  if (!startedAt) return ''
  const diff = Math.floor((Date.now() - new Date(startedAt).getTime()) / 60000)
  if (diff < 60) return diff + 'min'
  const h = Math.floor(diff / 60)
  const m = diff % 60
  return h + 'h' + (m > 0 ? m + 'min' : '')
}

async function fetchData() {
  try {
    const res = await api.get('/admin/tables')
    tables.value = res.data.tables
  } catch (err) {
    if (loading.value) console.error('Erro ao carregar mesas.')
  } finally {
    loading.value = false
  }
}

async function openDetail(table) {
  if (table.status !== 'ocupada') return
  detailLoading.value = true
  detailModal.value   = { table }
  try {
    const res = await api.get('/admin/tables/' + table.id + '/detail')
    detailModal.value = res.data
  } catch {
    detailModal.value.error = 'Erro ao carregar detalhes.'
  } finally {
    detailLoading.value = false
  }
}

function orderTotal(order) {
  if (!order.items) return 0
  return order.items.reduce((s, i) => s + parseFloat(i.unit_price) * i.quantity, 0)
}

function openTotal() {
  if (!detailModal.value?.orders?.length) return 0
  return detailModal.value.orders.reduce((s, o) => s + orderTotal(o), 0)
}

async function forceRelease(table) {
  if (!confirm('Liberar mesa ' + table.number + '?\nPedidos entregues não pagos serão cancelados.')) return
  releasing.value = table.id
  try {
    await api.post('/admin/tables/' + table.id + '/force-release')
    detailModal.value = null
    await fetchData()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao liberar mesa.')
  } finally {
    releasing.value = null
  }
}

onMounted(() => {
  fetchData()
  pollInterval = setInterval(fetchData, 10000)
})

onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Mapa de Mesas</h1>
        <p class="page-subtitle">Atualiza automaticamente a cada 10 segundos</p>
      </div>
      <button class="btn btn-secondary" @click="router.push({ name: 'tables-config' })">
        ⚙ Configurar mesas
      </button>
    </div>

    <!-- Legenda — dots vêm das cores de status tokens -->
    <div v-if="tables.length" class="map-legend">
      <span class="legend-item"><span class="legend-dot ld-pending"></span>Pendente</span>
      <span class="legend-item"><span class="legend-dot ld-received"></span>Em fila</span>
      <span class="legend-item"><span class="legend-dot ld-preparing"></span>Preparando</span>
      <span class="legend-item"><span class="legend-dot ld-ready"></span>Pronto p/ entregar</span>
      <span class="legend-item"><span class="legend-dot ld-delivered"></span>Servida</span>
      <span class="legend-item"><span class="legend-dot ld-free"></span>Livre</span>
    </div>

    <div v-if="loading" class="loading">Carregando mesas...</div>

    <div v-else-if="!tables.length" class="empty-state">
      Nenhuma mesa cadastrada.
      <button class="btn btn-primary" @click="router.push({ name: 'tables-config' })">
        Configurar mesas
      </button>
    </div>

    <div v-else class="tables-grid">
      <div
        v-for="t in tables"
        :key="t.id"
        class="table-card"
        :class="[getTablePriority(t), { 'is-clickable': t.status === 'ocupada' }]"
        @click="openDetail(t)"
      >
        <span v-if="t.undelivered_items > 0" class="item-badge">{{ t.undelivered_items }}</span>

        <div class="table-number">{{ t.number }}</div>

        <template v-if="t.status === 'ocupada'">
          <div class="table-client">{{ t.client_name || '—' }}</div>
          <div class="table-meta">
            <span v-if="t.session_started_at">🕐 {{ formatDuration(t.session_started_at) }}</span>
            <span v-if="t.open_value > 0">{{ formatMoney(t.open_value) }}</span>
          </div>
        </template>

        <div class="table-status-label">{{ getStatusLabel(t) }}</div>
        <div class="table-capacity">{{ t.capacity }} lugares</div>
        <div v-if="t.status === 'ocupada'" class="table-tap-hint">toque para ver</div>
      </div>
    </div>

    <!-- ── Modal de detalhe ── -->
    <div v-if="detailModal" class="modal-overlay" @click.self="detailModal = null">
      <div class="modal-box modal-box-wide">
        <div class="modal-title">
          Mesa {{ detailModal.table?.number }}
          <span class="badge badge-accent">Ocupada</span>
        </div>

        <div v-if="detailLoading" class="loading">Carregando...</div>

        <template v-else>
          <div v-if="detailModal.client" class="detail-client-bar">
            <div class="dcb-avatar">{{ (detailModal.client.name || '?')[0].toUpperCase() }}</div>
            <div class="dcb-info">
              <div class="dcb-name">{{ detailModal.client.name }}</div>
              <div class="dcb-phone">{{ detailModal.client.phone }}</div>
            </div>
            <div v-if="detailModal.session" class="dcb-time">
              🕐 {{ formatDuration(detailModal.session.started_at) }}
            </div>
          </div>

          <div v-if="detailModal.error" class="error-msg">{{ detailModal.error }}</div>

          <div v-if="!detailModal.orders?.length" class="empty-state detail-empty">
            Nenhum pedido ativo.
          </div>

          <div v-for="order in detailModal.orders" :key="order.id" class="detail-order">
            <div class="detail-order-head">
              <span class="detail-order-id">#{{ order.id }}</span>
              <span class="badge" :class="orderStatusBadgeClass[order.status] || 'badge-inactive'">
                {{ orderStatusLabels[order.status] || order.status }}
              </span>
              <span class="detail-order-total">{{ formatMoney(orderTotal(order)) }}</span>
            </div>
            <ul class="detail-items">
              <li v-for="item in order.items" :key="item.id">
                <span class="item-qty">{{ item.quantity }}×</span>
                <span class="item-name">{{ item.product.name }}</span>
                <span class="item-price">{{ formatMoney(item.unit_price * item.quantity) }}</span>
                <span v-if="item.notes" class="item-notes">({{ item.notes }})</span>
              </li>
            </ul>
          </div>

          <div v-if="detailModal.orders?.length" class="detail-total">
            Total em aberto:
            <strong>{{ formatMoney(openTotal()) }}</strong>
          </div>

          <div class="modal-actions">
            <button
              class="btn btn-force"
              :disabled="releasing === detailModal.table?.id"
              @click="forceRelease(detailModal.table)"
            >
              {{ releasing === detailModal.table?.id ? 'Liberando...' : '🔓 Liberar mesa' }}
            </button>
            <button class="btn btn-secondary" @click="detailModal = null">Fechar</button>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
/* Legenda do mapa — dots usam cores de status tokens */
.map-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  margin-bottom: 20px;
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
}
.legend-item { display: flex; align-items: center; gap: 5px; }
.legend-dot  { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.ld-pending   { background: var(--status-pending-color); }
.ld-received  { background: var(--status-received-color); }
.ld-preparing { background: var(--status-preparing-color); }
.ld-ready     { background: var(--status-ready-color); }
.ld-delivered { background: var(--status-delivered-color); }
.ld-free      { background: var(--admin-text-muted); opacity: 0.5; }

/* ── Grid e cards de mesa ── */
.tables-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 14px;
}

.table-card {
  position: relative;
  border: 2px solid var(--admin-border);
  border-radius: var(--radius);
  padding: 18px 14px 14px;
  text-align: center;
  background: var(--admin-bg-card);
  transition: border-color var(--dur-normal), background var(--dur-normal), transform var(--dur-fast), box-shadow var(--dur-fast);
  user-select: none;
}
.table-card.is-clickable { cursor: pointer; }
.table-card.is-clickable:hover {
  transform: translateY(-2px);
  box-shadow: var(--shadow);
}

/* Prioridades: .priority-* vêm do style.css global.
   Aqui só sobrescrevemos o que é exclusivo do admin (borda 2px). */
.priority-pending,
.priority-ready,
.priority-preparing,
.priority-received,
.priority-delivered {
  border-width: 2px;
}

.item-badge {
  position: absolute;
  top: -9px;
  right: -9px;
  background: var(--status-danger-color);
  color: #FFFFFF;
  font-size: var(--fs-xs);
  font-weight: var(--fw-bold);
  border-radius: 50%;
  width: 22px;
  height: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 2px 6px rgba(230, 57, 70, 0.4);
}

.table-number {
  font-size: 32px;
  font-weight: var(--fw-black);
  color: var(--admin-text-primary);
  line-height: 1;
  margin-bottom: 6px;
  letter-spacing: -0.5px;
}
.table-client {
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  color: var(--admin-text-primary);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.table-meta {
  display: flex;
  justify-content: center;
  gap: 8px;
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  margin-top: 3px;
  font-variant-numeric: tabular-nums;
}
.table-status-label {
  font-size: var(--fs-xs);
  font-weight: var(--fw-bold);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-top: 8px;
  color: var(--admin-text-muted);
}
.table-capacity {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  margin-top: 3px;
}
.table-tap-hint {
  font-size: 10px;
  color: var(--admin-text-muted);
  margin-top: 6px;
  opacity: 0.6;
  font-style: italic;
}

/* ── Modal de detalhe ── */
.detail-client-bar {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px;
  background: var(--admin-bg-hover);
  border-radius: var(--radius-sm);
  margin-bottom: 16px;
}
.dcb-avatar {
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: var(--brand-accent);
  color: #FFFFFF;
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.dcb-info   { flex: 1; min-width: 0; }
.dcb-name   { font-size: 15px; font-weight: var(--fw-bold); color: var(--admin-text-primary); }
.dcb-phone  { font-size: 12px; color: var(--admin-text-muted); }
.dcb-time {
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  color: var(--admin-text-muted);
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.detail-empty { padding: 20px; }

.detail-order {
  border: 1px solid var(--admin-border);
  border-radius: var(--radius-sm);
  padding: 12px;
  margin-bottom: 10px;
  background: var(--admin-bg-secondary);
}
.detail-order-head {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}
.detail-order-id {
  font-weight: var(--fw-bold);
  font-size: var(--fs-sm);
  color: var(--admin-text-muted);
  font-variant-numeric: tabular-nums;
}
.detail-order-total {
  margin-left: auto;
  font-weight: var(--fw-bold);
  font-size: var(--fs-sm);
  color: var(--admin-text-primary);
  font-variant-numeric: tabular-nums;
}

.detail-items {
  list-style: none;
  padding: 0;
  margin: 0;
}
.detail-items li {
  display: flex;
  align-items: baseline;
  gap: 6px;
  font-size: var(--fs-sm);
  padding: 4px 0;
  border-bottom: 1px dashed var(--admin-border);
  color: var(--admin-text-primary);
}
.detail-items li:last-child { border-bottom: none; }

.item-qty {
  font-weight: var(--fw-bold);
  color: var(--brand-accent);
  min-width: 24px;
  font-variant-numeric: tabular-nums;
}
.item-name { flex: 1; }
.item-price {
  margin-left: auto;
  color: var(--admin-text-muted);
  font-size: var(--fs-xs);
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}
.item-notes {
  display: block;
  width: 100%;
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  font-style: italic;
  margin-top: 2px;
  margin-left: 30px;
}

.detail-total {
  text-align: right;
  padding: 12px 0 4px;
  font-size: var(--fs-md);
  color: var(--admin-text-muted);
  border-top: 1px solid var(--admin-border);
  margin-top: 4px;
}
.detail-total strong {
  color: var(--admin-text-primary);
  font-size: var(--fs-lg);
  font-variant-numeric: tabular-nums;
}

@media (max-width: 600px) {
  .tables-grid   { grid-template-columns: repeat(3, 1fr); gap: 8px; }
  .table-number  { font-size: 24px; }
}
</style>
