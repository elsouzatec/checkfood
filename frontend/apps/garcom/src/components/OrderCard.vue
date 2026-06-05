<script setup>
import { computed } from 'vue'

const props = defineProps({
  order:         { type: Object,  required: true },
  actionLoading: { type: String,  default: null },
  statusLabels:  { type: Object,  required: true },
  areaLabels:    { type: Object,  required: true },
  printEnabled:  { type: Boolean, default: false },
})

const emit = defineEmits(['approve', 'reject', 'quick-deliver', 'deliver', 'pick-and-deliver', 'remove-item'])

// ── Lógica local (extraída do TableOrdersView) ──

function groupItemsByArea(items) {
  const groups = {}
  for (const item of items) {
    const area = item.product?.category?.preparation_area || 'cozinha'
    if (!groups[area]) groups[area] = []
    groups[area].push(item)
  }
  return groups
}

const groupedItems = computed(() => groupItemsByArea(props.order.items))

const canQuickDeliver = computed(() => {
  if (props.order.status !== 'confirmado') return false
  return props.order.items.every(item => {
    const area = item.product?.category?.preparation_area
    return area === 'bar' || area === 'lanchonete'
  })
})

const orderTotal = computed(() =>
  props.order.items.reduce(
    (sum, item) => sum + parseFloat(item.unit_price) * item.quantity,
    0
  )
)

function formatPrice(value) {
  return parseFloat(value).toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

// ── Emissores ──
function onApprove()              { emit('approve', props.order.id) }
function onReject()               { emit('reject',  props.order.id) }
function onQuickDeliver()         { emit('quick-deliver', props.order.id) }
function onDeliver()              { emit('deliver', props.order.id) }
function onPickAndDeliver()       { emit('pick-and-deliver', props.order.id) }
function onRemoveItem(itemId)     { emit('remove-item', { orderId: props.order.id, itemId }) }
</script>

<template>
  <div class="order-card">

    <!-- Cabeçalho -->
    <div class="order-header">
      <div class="order-head-left">
        <span class="order-id">Pedido #{{ order.id }}</span>
        <span v-if="order.user" class="order-user">{{ order.user.name }}</span>
      </div>
      <span class="badge" :class="'badge-status-' + order.status">
        {{ statusLabels[order.status] || order.status }}
      </span>
    </div>

    <!-- Itens agrupados por área -->
    <div
      v-for="(items, area) in groupedItems"
      :key="area"
      class="order-area-group"
    >
      <div class="area-pill" :class="'area-' + area">
        {{ areaLabels[area] || area }}
      </div>

      <ul class="order-items-list">
        <li v-for="item in items" :key="item.id" class="order-item-row">
          <div class="order-item-info">
            <span class="order-item-qty">{{ item.quantity }}×</span>
            <span class="order-item-name">{{ item.product.name }}</span>
            <span class="order-item-price">
              R$ {{ formatPrice(parseFloat(item.unit_price) * item.quantity) }}
            </span>
            <div v-if="item.notes" class="order-item-notes">{{ item.notes }}</div>
          </div>
          <button
            v-if="order.status === 'pendente_validacao' && order.items.length > 1"
            class="remove-item-btn"
            @click="onRemoveItem(item.id)"
            aria-label="Remover item"
          >✕</button>
        </li>
      </ul>
    </div>

    <!-- Subtotal do pedido -->
    <div class="order-total">
      Total: R$ {{ formatPrice(orderTotal) }}
    </div>

    <!-- Ações -->
    <div class="order-actions">
      <template v-if="order.status === 'pendente_validacao'">
        <button
          class="btn btn-success btn-sm"
          :disabled="actionLoading === `approve-${order.id}`"
          @click="onApprove"
        >
          <span v-if="actionLoading === `approve-${order.id}`" class="btn-spinner"></span>
          <span v-else>✓ Aprovar</span>
        </button>
        <button
          class="btn btn-danger btn-sm"
          :disabled="actionLoading === `reject-${order.id}`"
          @click="onReject"
        >
          <span v-if="actionLoading === `reject-${order.id}`" class="btn-spinner"></span>
          <span v-else>✕ Rejeitar</span>
        </button>
      </template>

      <button
        v-if="canQuickDeliver"
        class="btn btn-success btn-block"
        :disabled="actionLoading === `quick-${order.id}`"
        @click="onQuickDeliver"
      >
        <span v-if="actionLoading === `quick-${order.id}`" class="btn-spinner"></span>
        <span v-else>🍽 Confirmar Entrega</span>
      </button>

      <!-- Modo impressão térmica: garçom controla "pronto + entregue" sem KDS -->
      <button
        v-if="printEnabled && (order.status === 'confirmado' || order.status === 'preparando') && !canQuickDeliver"
        class="btn btn-success btn-block"
        :disabled="actionLoading === `pick-${order.id}`"
        @click="onPickAndDeliver"
      >
        <span v-if="actionLoading === `pick-${order.id}`" class="btn-spinner"></span>
        <span v-else>🖨 Pegar e Entregar</span>
      </button>

      <button
        v-if="order.status === 'pronto'"
        class="btn btn-success btn-block"
        :disabled="actionLoading === `deliver-${order.id}`"
        @click="onDeliver"
      >
        <span v-if="actionLoading === `deliver-${order.id}`" class="btn-spinner"></span>
        <span v-else>🍽 Confirmar Entrega</span>
      </button>
    </div>

  </div>
</template>

<style scoped>
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

/* Agrupamento por área */
.order-area-group { margin-bottom: 12px; }
.order-area-group:last-of-type { margin-bottom: 0; }

/* Área pills — cores via classe, sem inline style */
.area-pill {
  display: inline-block;
  padding: 3px 10px;
  border-radius: var(--radius-pill);
  font-size: var(--fs-xs);
  font-weight: var(--fw-bold);
  margin-bottom: 6px;
  border: 1px solid;
}
.area-cozinha    { background: rgba(230, 57, 70,  0.12); color: var(--c-red);  border-color: rgba(230, 57, 70,  0.38); }
.area-bar        { background: rgba(69,  123, 157, 0.12); color: var(--c-blue); border-color: rgba(69,  123, 157, 0.38); }
.area-lanchonete { background: rgba(184, 134, 11,  0.12); color: #B8860B;       border-color: rgba(184, 134, 11,  0.38); }

/* Linhas de itens */
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
  transition: background var(--dur-fast) var(--ease-out);
  flex-shrink: 0;
}
.remove-item-btn:hover { background: var(--status-pending-bg); }
</style>


