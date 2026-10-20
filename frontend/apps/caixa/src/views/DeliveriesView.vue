<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

// Entregas: aceitar → (cozinha prepara) → saiu → entregue → acerto no caixa.
// O acerto usa a tela de sessão de sempre (/sessao/:id), com troco e divisão.
const router = useRouter()

const deliveries = ref([])
const loading    = ref(true)
const busyId     = ref(null)
const error      = ref('')
let pollTimer    = null

const methodLabels = { dinheiro: 'Dinheiro', cartao: 'Cartão', pix: 'PIX' }

const columns = [
  { key: 'novas',    title: 'Novas',            empty: 'Nenhum pedido novo.' },
  { key: 'preparo',  title: 'Em preparo',       empty: 'Nada em preparo.' },
  { key: 'caminho',  title: 'A caminho',        empty: 'Nenhuma entrega na rua.' },
  { key: 'acerto',   title: 'Aguardando acerto', empty: 'Nada para acertar.' },
]

function columnOf(d) {
  if (d.status === 'at_cashier') return 'acerto'
  if (d.status === 'closed') return 'encerradas'
  return { aguardando: 'novas', em_preparo: 'preparo', a_caminho: 'caminho' }[d.stage] || 'novas'
}

const byColumn = computed(() => {
  const map = { novas: [], preparo: [], caminho: [], acerto: [], encerradas: [] }
  for (const d of deliveries.value) map[columnOf(d)].push(d)
  return map
})

async function load() {
  try {
    const { data } = await api.get('/caixa/deliveries')
    deliveries.value = data.deliveries
    error.value = ''
  } catch (err) {
    error.value = 'Erro ao carregar entregas.'
  } finally {
    loading.value = false
  }
}

async function act(d, action, body) {
  busyId.value = d.id
  error.value = ''
  try {
    const { data } = await api.post(`/caixa/deliveries/${d.id}/${action}`, body || {})
    const i = deliveries.value.findIndex(x => x.id === d.id)
    if (i >= 0) deliveries.value[i] = data.delivery
    return data.delivery
  } catch (err) {
    error.value = err.response?.data?.message || 'Não foi possível atualizar a entrega.'
    return null
  } finally {
    busyId.value = null
  }
}

function accept(d) { act(d, 'accept') }

function dispatchOut(d) {
  if (!d.kitchen_done && !confirm('A cozinha ainda não marcou tudo como pronto. Despachar mesmo assim?')) return
  act(d, 'dispatch')
}

async function delivered(d) {
  const updated = await act(d, 'delivered')
  if (updated) router.push(`/sessao/${d.id}`)
}

function cancel(d) {
  const reason = prompt('Motivo do cancelamento (o cliente verá o pedido como cancelado):')
  if (!reason) return
  act(d, 'cancel', { reason })
}

function formatPrice(v) {
  return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

function phoneHref(p) {
  return 'tel:' + String(p || '').replace(/\D/g, '')
}

onMounted(() => {
  load()
  pollTimer = setInterval(load, 15000)
})

onUnmounted(() => clearInterval(pollTimer))
</script>

<template>
  <div class="cx-deliv">
    <div class="cx-deliv__head">
      <h1 class="cx-deliv__title">🛵 Entregas</h1>
      <span class="cx-deliv__hint">Atualiza a cada 15 s</span>
    </div>

    <div v-if="error" class="cx-deliv__error">{{ error }}</div>
    <p v-if="loading" class="cx-deliv__empty">Carregando...</p>

    <div v-else class="cx-deliv__board">
      <section v-for="col in columns" :key="col.key" class="cx-deliv__col">
        <h2 class="cx-deliv__col-title">
          {{ col.title }}
          <span v-if="byColumn[col.key].length" class="cx-deliv__count">{{ byColumn[col.key].length }}</span>
        </h2>

        <p v-if="!byColumn[col.key].length" class="cx-deliv__empty">{{ col.empty }}</p>

        <article
          v-for="d in byColumn[col.key]"
          :key="d.id"
          class="cx-dcard"
          :class="{ 'cx-dcard--new': col.key === 'novas' }"
        >
          <div class="cx-dcard__top">
            <div>
              <div class="cx-dcard__customer">{{ d.customer.name }}</div>
              <a class="cx-dcard__phone" :href="phoneHref(d.customer.phone)">{{ d.customer.phone }}</a>
            </div>
            <div class="cx-dcard__time">
              Pedido {{ formatTime(d.created_at) }}
              <template v-if="d.dispatched_at"><br>Saiu {{ formatTime(d.dispatched_at) }}</template>
            </div>
          </div>

          <div class="cx-dcard__addr">
            📍 {{ d.address_line }}
            <span v-if="d.address?.reference" class="cx-dcard__ref">Ref.: {{ d.address.reference }}</span>
          </div>
          <div v-if="d.notes" class="cx-dcard__notes">“{{ d.notes }}”</div>

          <ul class="cx-dcard__items">
            <template v-for="o in d.orders" :key="o.id">
              <li v-for="(it, i) in o.items" :key="o.id + '-' + i" :class="{ 'is-cancelled': o.status === 'cancelado' }">
                <strong>{{ it.quantity }}×</strong> {{ it.name }}
                <span v-if="it.notes" class="cx-dcard__item-notes">— {{ it.notes }}</span>
              </li>
            </template>
          </ul>

          <div class="cx-dcard__pay">
            <span class="cx-method-pill" :class="`cx-method-pill--${d.payment_method}`">{{ methodLabels[d.payment_method] || d.payment_method }}</span>
            <span v-if="d.change_for" class="cx-dcard__change">troco p/ {{ formatPrice(d.change_for) }}</span>
            <span class="cx-dcard__total">{{ formatPrice(d.total) }}</span>
          </div>
          <div v-if="d.fee" class="cx-dcard__fee">inclui taxa de entrega {{ formatPrice(d.fee) }}</div>

          <div v-if="col.key === 'preparo'" class="cx-dcard__kitchen" :class="{ 'is-done': d.kitchen_done }">
            {{ d.kitchen_done ? '✓ Cozinha: pronto' : '⏳ Cozinha preparando' }}
          </div>

          <div class="cx-dcard__actions">
            <template v-if="col.key === 'novas'">
              <button class="cx-btn cx-btn--primary" :disabled="busyId === d.id" @click="accept(d)">Aceitar</button>
              <button class="cx-btn cx-btn--secondary" :disabled="busyId === d.id" @click="cancel(d)">Recusar</button>
            </template>
            <template v-else-if="col.key === 'preparo'">
              <button class="cx-btn cx-btn--primary" :disabled="busyId === d.id" @click="dispatchOut(d)">Saiu para entrega</button>
              <button class="cx-btn cx-btn--secondary" :disabled="busyId === d.id" @click="cancel(d)">Cancelar</button>
            </template>
            <template v-else-if="col.key === 'caminho'">
              <button class="cx-btn cx-btn--primary" :disabled="busyId === d.id" @click="delivered(d)">Entregue — receber pagamento</button>
            </template>
            <template v-else-if="col.key === 'acerto'">
              <button class="cx-btn cx-btn--primary" @click="router.push(`/sessao/${d.id}`)">Receber pagamento</button>
            </template>
          </div>
        </article>
      </section>
    </div>

    <details v-if="byColumn.encerradas.length" class="cx-deliv__done">
      <summary>Encerradas hoje ({{ byColumn.encerradas.length }})</summary>
      <div v-for="d in byColumn.encerradas" :key="d.id" class="cx-deliv__done-row">
        <span>{{ formatTime(d.created_at) }} · {{ d.customer.name }}</span>
        <span>{{ d.stage === 'cancelado' ? 'Cancelada' : formatPrice(d.total) }}</span>
      </div>
    </details>
  </div>
</template>

<style scoped>
.cx-deliv {
  max-width: var(--cx-container-wide);
  margin: 0 auto;
  padding: 20px 16px 40px;
}
.cx-deliv__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 14px;
}
.cx-deliv__title { font-size: 22px; font-weight: 800; color: var(--cx-text); }
.cx-deliv__hint { font-size: 12px; color: var(--cx-text-muted); }
.cx-deliv__error {
  background: var(--cx-danger-soft);
  color: var(--cx-danger);
  border-radius: var(--cx-radius-sm);
  padding: 10px 14px;
  margin-bottom: 12px;
  font-weight: 600;
}
.cx-deliv__board {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}
@media (min-width: 1024px) {
  .cx-deliv__board { grid-template-columns: repeat(4, minmax(0, 1fr)); align-items: start; }
}
.cx-deliv__col-title {
  font-size: 13px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--cx-text-soft);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.cx-deliv__count {
  background: var(--cx-accent);
  color: var(--cx-accent-on);
  border-radius: 999px;
  padding: 1px 8px;
  font-size: 12px;
}
.cx-deliv__empty { font-size: 13px; color: var(--cx-text-muted); padding: 8px 2px; }

.cx-dcard {
  background: var(--cx-surface);
  border: 1px solid var(--cx-border);
  border-radius: var(--cx-radius-lg);
  box-shadow: var(--cx-shadow-card);
  padding: 14px;
  margin-bottom: 12px;
}
.cx-dcard--new { border: 2px solid var(--cx-accent); }
.cx-dcard__top { display: flex; justify-content: space-between; gap: 10px; }
.cx-dcard__customer { font-size: 16px; font-weight: 800; color: var(--cx-text); }
.cx-dcard__phone { font-size: 13px; color: var(--cx-accent); text-decoration: none; font-weight: 600; }
.cx-dcard__time { font-size: 12px; color: var(--cx-text-muted); text-align: right; white-space: nowrap; }
.cx-dcard__addr { font-size: 14px; color: var(--cx-text); margin-top: 10px; line-height: 1.4; }
.cx-dcard__ref { display: block; font-size: 12px; color: var(--cx-text-muted); }
.cx-dcard__notes { font-size: 13px; color: var(--cx-text-soft); font-style: italic; margin-top: 6px; }
.cx-dcard__items {
  list-style: none;
  padding: 8px 0 0;
  margin: 10px 0 0;
  border-top: 1px dashed var(--cx-border);
  font-size: 14px;
  color: var(--cx-text);
}
.cx-dcard__items li { padding: 2px 0; }
.cx-dcard__items li.is-cancelled { text-decoration: line-through; color: var(--cx-text-muted); }
.cx-dcard__item-notes { color: var(--cx-text-muted); font-size: 12px; }
.cx-dcard__pay {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--cx-border);
}
.cx-dcard__change { font-size: 12px; color: var(--cx-warning); font-weight: 700; }
.cx-dcard__total { margin-left: auto; font-size: 17px; font-weight: 800; color: var(--cx-text); }
.cx-dcard__fee { font-size: 11px; color: var(--cx-text-muted); text-align: right; }
.cx-dcard__kitchen {
  margin-top: 8px;
  font-size: 12px;
  font-weight: 700;
  color: var(--cx-warning);
}
.cx-dcard__kitchen.is-done { color: var(--cx-success); }
.cx-dcard__actions { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; }
.cx-dcard__actions .cx-btn { flex: 1; }

.cx-deliv__done {
  margin-top: 20px;
  background: var(--cx-surface);
  border: 1px solid var(--cx-border);
  border-radius: var(--cx-radius);
  padding: 12px 14px;
  font-size: 14px;
  color: var(--cx-text);
}
.cx-deliv__done summary { cursor: pointer; font-weight: 700; }
.cx-deliv__done-row {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  border-top: 1px solid var(--cx-border);
  margin-top: 6px;
  color: var(--cx-text-soft);
}
</style>
