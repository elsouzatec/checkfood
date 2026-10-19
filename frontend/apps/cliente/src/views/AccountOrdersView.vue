<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import accountApi from '../accountApi'
import { useAccountStore } from '../stores/account'

var route = useRoute()
var router = useRouter()
var account = useAccountStore()

var orders = ref([])
var deliveries = ref({})
var page = ref(0)
var lastPage = ref(1)
var loading = ref(false)
var error = ref('')
var justPlaced = ref(route.query.entrega ? Number(route.query.entrega) : null)
var pollTimer = null

var statusLabels = {
  pendente_validacao: 'Aguardando',
  confirmado: 'Na fila',
  preparando: 'Preparando',
  pronto: 'Pronto',
  entregue: 'Entregue',
  pago: 'Pago',
  cancelado: 'Cancelado',
}

// Etapas da entrega (sessão) → rótulo + cor do badge
var stageLabels = {
  aguardando: 'Aguardando o restaurante',
  em_preparo: 'Em preparo',
  a_caminho: 'Saiu para entrega 🛵',
  entregue: 'Entregue',
  cancelado: 'Cancelado',
}
var stageBadge = {
  aguardando: 'pendente_validacao',
  em_preparo: 'preparando',
  a_caminho: 'pronto',
  entregue: 'entregue',
  cancelado: 'cancelado',
}
var paymentLabels = { dinheiro: 'Dinheiro', cartao: 'Cartão', pix: 'PIX' }
var ACTIVE_STAGES = ['aguardando', 'em_preparo', 'a_caminho']

// Uma entrega pode ter virado vários pedidos (um por setor): vira um cartão só.
var cards = computed(function() {
  var list = []
  var bySession = {}
  orders.value.forEach(function(o) {
    if (o.type !== 'entrega') {
      list.push({ kind: 'mesa', key: 'o' + o.id, order: o })
      return
    }
    var card = bySession[o.session_id]
    if (!card) {
      card = { kind: 'entrega', key: 's' + o.session_id, sessionId: o.session_id, created_at: o.created_at, items: [], itemsTotal: 0, delivery: deliveries.value[o.session_id] || {} }
      bySession[o.session_id] = card
      list.push(card)
    }
    if (o.status !== 'cancelado') card.itemsTotal += o.total
    card.items = card.items.concat(o.items)
    if (o.created_at < card.created_at) card.created_at = o.created_at
  })
  return list
})

var hasActiveDelivery = computed(function() {
  return Object.values(deliveries.value).some(function(d) { return ACTIVE_STAGES.includes(d.stage) })
})

async function fetchPage(p) {
  var res = await accountApi.get('/client/orders', { params: { page: p } })
  return res.data
}

async function loadMore() {
  if (loading.value || page.value >= lastPage.value) return
  loading.value = true
  error.value = ''
  try {
    var data = await fetchPage(page.value + 1)
    orders.value = orders.value.concat(data.data)
    deliveries.value = Object.assign({}, deliveries.value, data.deliveries)
    page.value = data.current_page
    lastPage.value = data.last_page
  } catch (e) {
    error.value = 'Não foi possível carregar seus pedidos.'
  } finally {
    loading.value = false
  }
}

// Entrega em andamento: atualiza a 1ª página para acompanhar a etapa.
async function refreshFirstPage() {
  if (!hasActiveDelivery.value || loading.value) return
  try {
    var data = await fetchPage(1)
    var fresh = data.data
    // Ao aceitar, o pedido original vira sub-pedidos por setor (ids novos):
    // troca a 1ª página inteira e mantém só o que é mais antigo que ela.
    var oldest = fresh.length ? fresh[fresh.length - 1].created_at : null
    var older = page.value > 1 && oldest
      ? orders.value.filter(function(o) { return o.created_at < oldest })
      : []
    orders.value = fresh.concat(older)
    deliveries.value = Object.assign({}, deliveries.value, data.deliveries)
  } catch (e) { /* tenta de novo no próximo ciclo */ }
}

function formatMoney(val) {
  return Number(val).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatDate(iso) {
  return new Date(iso).toLocaleString('pt-BR', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

onMounted(function() {
  loadMore()
  pollTimer = setInterval(refreshFirstPage, 20000)
})

onUnmounted(function() {
  clearInterval(pollTimer)
})
</script>

<template>
  <div>
    <header class="acc-header">
      <button class="acc-header-back" aria-label="Voltar" @click="router.push('/minha-conta')">←</button>
      <div class="acc-header-text">
        <div class="acc-header-restaurant">{{ account.restaurant?.name }}</div>
        <div class="acc-header-title">Meus pedidos</div>
      </div>
    </header>

    <main class="acc-page">
      <div v-if="justPlaced" class="msg msg-success">
        Pedido de entrega enviado! Acompanhe aqui — a página se atualiza sozinha.
      </div>
      <div v-if="error" class="msg msg-error">{{ error }}</div>

      <div v-if="page > 0 && orders.length === 0" class="empty-state">
        <div class="icon">🧾</div>
        <p>Nenhum pedido ainda</p>
        <span class="hint">Os pedidos que você fizer com a conta aparecem aqui.</span>
      </div>

      <template v-for="card in cards" :key="card.key">
        <!-- Entrega -->
        <article v-if="card.kind === 'entrega'" class="order-row order-row--delivery" :class="{ 'is-new': card.sessionId === justPlaced }">
          <div class="order-row-head">
            <div>
              <div class="order-row-tag">🛵 Entrega</div>
              <div class="order-row-date">{{ formatDate(card.created_at) }}</div>
              <div class="order-row-meta">{{ card.delivery.address_line }}</div>
            </div>
            <span class="badge badge-sm" :class="'badge-status-' + (stageBadge[card.delivery.stage] || 'pendente_validacao')">
              {{ stageLabels[card.delivery.stage] || 'Em andamento' }}
            </span>
          </div>
          <ul class="order-row-items">
            <li v-for="(item, i) in card.items" :key="i">
              <span>{{ item.quantity }}× {{ item.name }}</span>
              <span>{{ formatMoney(item.unit_price * item.quantity) }}</span>
            </li>
            <li v-if="card.delivery.fee > 0">
              <span>Taxa de entrega</span>
              <span>{{ formatMoney(card.delivery.fee) }}</span>
            </li>
          </ul>
          <div class="order-row-total" :class="{ 'is-cancelled': card.delivery.stage === 'cancelado' }">
            <span>{{ paymentLabels[card.delivery.payment_method] || '' }} na entrega</span>
            <strong>{{ formatMoney(card.itemsTotal + (card.itemsTotal > 0 ? (card.delivery.fee || 0) : 0)) }}</strong>
          </div>
        </article>

        <!-- Mesa -->
        <article v-else class="order-row">
          <div class="order-row-head">
            <div>
              <div class="order-row-date">{{ formatDate(card.order.created_at) }}</div>
              <div class="order-row-meta">Pedido #{{ card.order.id }}<template v-if="card.order.table_number"> · Mesa {{ card.order.table_number }}</template></div>
            </div>
            <span class="badge badge-sm" :class="'badge-status-' + card.order.status">
              {{ statusLabels[card.order.status] || card.order.status }}
            </span>
          </div>
          <ul class="order-row-items">
            <li v-for="(item, i) in card.order.items" :key="i">
              <span>{{ item.quantity }}× {{ item.name }}</span>
              <span>{{ formatMoney(item.unit_price * item.quantity) }}</span>
            </li>
          </ul>
          <div class="order-row-total" :class="{ 'is-cancelled': card.order.status === 'cancelado' }">
            Total <strong>{{ formatMoney(card.order.total) }}</strong>
          </div>
        </article>
      </template>

      <p v-if="loading" class="loading">Carregando...</p>
      <button v-else-if="page < lastPage" class="entry-secondary-btn" @click="loadMore">Carregar mais</button>
    </main>
  </div>
</template>

<style scoped>
.order-row {
  background: var(--surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  padding: 14px 16px;
  margin-bottom: 10px;
}
.order-row--delivery { border-left: 4px solid var(--brand-accent); }
.order-row.is-new { box-shadow: 0 0 0 2px var(--brand-accent); }
.order-row-tag {
  font-size: var(--fs-xs);
  font-weight: var(--fw-black);
  color: var(--brand-accent);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 2px;
}
.order-row-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 10px;
  margin-bottom: 10px;
}
.order-row-date {
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
}
.order-row-meta {
  font-size: var(--fs-xs);
  color: var(--text-muted);
  margin-top: 2px;
}
.order-row-items {
  list-style: none;
  padding: 0;
  margin: 0;
  border-top: 1px dashed var(--border-default);
  padding-top: 8px;
}
.order-row-items li {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  font-size: var(--fs-sm);
  color: var(--text-secondary);
  padding: 3px 0;
}
.order-row-total {
  display: flex;
  justify-content: space-between;
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid var(--border-subtle);
  font-size: var(--fs-sm);
  color: var(--text-muted);
}
.order-row-total strong { color: var(--text-primary); font-size: var(--fs-md); }
.order-row-total.is-cancelled strong { text-decoration: line-through; color: var(--text-muted); }
</style>
