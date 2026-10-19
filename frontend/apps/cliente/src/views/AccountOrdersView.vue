<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import accountApi from '../accountApi'
import { useAccountStore } from '../stores/account'

var router = useRouter()
var account = useAccountStore()

var orders = ref([])
var page = ref(0)
var lastPage = ref(1)
var loading = ref(false)
var error = ref('')

var statusLabels = {
  pendente_validacao: 'Aguardando',
  confirmado: 'Na fila',
  preparando: 'Preparando',
  pronto: 'Pronto',
  entregue: 'Entregue',
  pago: 'Pago',
  cancelado: 'Cancelado',
}

async function loadMore() {
  if (loading.value || page.value >= lastPage.value) return
  loading.value = true
  error.value = ''
  try {
    var res = await accountApi.get('/client/orders', { params: { page: page.value + 1 } })
    orders.value = orders.value.concat(res.data.data)
    page.value = res.data.current_page
    lastPage.value = res.data.last_page
  } catch (e) {
    error.value = 'Não foi possível carregar seus pedidos.'
  } finally {
    loading.value = false
  }
}

function formatMoney(val) {
  return Number(val).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatDate(iso) {
  return new Date(iso).toLocaleString('pt-BR', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
  })
}

onMounted(loadMore)
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
      <div v-if="error" class="msg msg-error">{{ error }}</div>

      <div v-if="page > 0 && orders.length === 0" class="empty-state">
        <div class="icon">🧾</div>
        <p>Nenhum pedido ainda</p>
        <span class="hint">Os pedidos que você fizer com a conta aparecem aqui.</span>
      </div>

      <article v-for="order in orders" :key="order.id" class="order-row">
        <div class="order-row-head">
          <div>
            <div class="order-row-date">{{ formatDate(order.created_at) }}</div>
            <div class="order-row-meta">Pedido #{{ order.id }}<template v-if="order.table_number"> · Mesa {{ order.table_number }}</template></div>
          </div>
          <span class="badge badge-sm" :class="'badge-status-' + order.status">
            {{ statusLabels[order.status] || order.status }}
          </span>
        </div>
        <ul class="order-row-items">
          <li v-for="(item, i) in order.items" :key="i">
            <span>{{ item.quantity }}× {{ item.name }}</span>
            <span>{{ formatMoney(item.unit_price * item.quantity) }}</span>
          </li>
        </ul>
        <div class="order-row-total" :class="{ 'is-cancelled': order.status === 'cancelado' }">
          Total <strong>{{ formatMoney(order.total) }}</strong>
        </div>
      </article>

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
