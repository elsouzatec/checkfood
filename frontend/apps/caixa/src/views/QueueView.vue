<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useShiftStore } from '../stores/shift'
import api from '../api'

const router = useRouter()
const shift  = useShiftStore()

const queue        = ref([])
const loading      = ref(true)
const overdueCount = ref(0)
const selectedId   = ref(null)
const isDesktop    = ref(false)
let   pollInterval = null
let   mq           = null

const methodLabels = {
  pix: 'PIX', dinheiro: 'Dinheiro', cartao: 'Cartão', maquininha: 'Maquininha',
}

const selected = computed(() => queue.value.find(q => q.id === selectedId.value) || null)

const filterModes = [
  { key: 'all',     label: 'Tudo' },
  { key: 'overdue', label: 'Atrasadas' },
]
const filterMode = ref('all')

const visibleQueue = computed(() => {
  if (filterMode.value === 'overdue') return queue.value.filter(q => q.is_overdue)
  return queue.value
})

function formatPrice(v) {
  return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

function formatWait(minutes) {
  const m = Math.floor(Number(minutes) || 0)
  if (m < 60) return `${m}min`
  const h = Math.floor(m / 60)
  const rem = m % 60
  return rem > 0 ? `${h}h${rem}` : `${h}h`
}

function onSelect(item) {
  if (isDesktop.value) {
    selectedId.value = item.id
  } else {
    router.push(`/sessao/${item.id}`)
  }
}

function openSelected() {
  if (selectedId.value) router.push(`/sessao/${selectedId.value}`)
}

async function fetchQueue() {
  try {
    const { data } = await api.get('/caixa/queue')
    queue.value        = data.queue
    overdueCount.value = data.overdue_count
    if (isDesktop.value && !selectedId.value && queue.value.length > 0) {
      selectedId.value = queue.value[0].id
    }
    if (selectedId.value && !queue.value.some(q => q.id === selectedId.value)) {
      selectedId.value = queue.value[0]?.id ?? null
    }
    // mantém o "Recebido" do header em dia
    try { await shift.fetch() } catch { /* silencia */ }
  } catch (err) {
    console.error('Erro ao buscar fila:', err)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  mq = window.matchMedia('(min-width: 1024px)')
  isDesktop.value = mq.matches
  const onChange = e => { isDesktop.value = e.matches }
  mq.addEventListener('change', onChange)
  onUnmounted(() => mq.removeEventListener('change', onChange))

  fetchQueue()
  pollInterval = setInterval(fetchQueue, 5000)
})
onUnmounted(() => {
  if (pollInterval) clearInterval(pollInterval)
})
</script>

<template>
  <div class="cx-queue">
    <!-- Toolbar: contagem + filtros + refresh -->
    <div class="cx-queue__toolbar">
      <div class="cx-queue__count">
        <span class="cx-queue__count-num">{{ queue.length }}</span>
        <span class="cx-queue__count-label">na fila</span>
        <span v-if="overdueCount > 0" class="cx-queue__overdue-badge">
          {{ overdueCount }} atrasada{{ overdueCount > 1 ? 's' : '' }}
        </span>
      </div>

      <div class="cx-queue__filters">
        <button
          v-for="f in filterModes"
          :key="f.key"
          class="cx-filter-pill"
          :class="{ 'cx-filter-pill--active': filterMode === f.key }"
          @click="filterMode = f.key"
        >
          {{ f.label }}
        </button>
        <button class="cx-queue__refresh" title="Atualizar" @click="fetchQueue">↻</button>
      </div>
    </div>

    <div v-if="loading" class="cx-queue__loading">Carregando fila...</div>

    <div v-else class="cx-queue__body">
      <!-- ── Lista ── -->
      <div class="cx-queue__list">
        <div v-if="visibleQueue.length === 0" class="cx-queue__empty">
          <div class="cx-queue__empty-icon">✓</div>
          <div class="cx-queue__empty-title">
            {{ filterMode === 'overdue' ? 'Nenhuma atrasada' : 'Fila limpa' }}
          </div>
          <p class="cx-queue__empty-text">Nenhuma mesa aguardando pagamento</p>
        </div>

        <button
          v-for="item in visibleQueue"
          :key="item.id"
          class="cx-qcard"
          :class="{
            'cx-qcard--overdue':  item.is_overdue,
            'cx-qcard--selected': isDesktop && selectedId === item.id,
          }"
          @click="onSelect(item)"
        >
          <div class="cx-qcard__head">
            <div class="cx-qcard__id">
              <span class="cx-qcard__mesa">{{ item.type === 'entrega' ? '🛵 Entrega' : 'Mesa ' + (item.table?.number ?? '?') }}</span>
              <span
                v-if="item.type === 'entrega'"
                class="cx-qcard__guest"
              >{{ item.delivery?.customer_name }}<template v-if="item.delivery?.change_for"> · troco p/ {{ formatPrice(item.delivery.change_for) }}</template></span>
              <span
                v-else-if="item.guest_name"
                class="cx-qcard__guest"
              >{{ item.guest_name }}</span>
              <span v-else class="cx-qcard__guest cx-qcard__guest--empty">
                Cliente não identificado
              </span>
            </div>

            <span
              class="cx-qcard__wait-chip"
              :class="{ 'cx-qcard__wait-chip--overdue': item.is_overdue }"
            >
              <span class="cx-qcard__wait-dot" aria-hidden="true"></span>
              {{ formatWait(item.wait_minutes) }}
            </span>
          </div>

          <div class="cx-qcard__foot">
            <span class="cx-qcard__meta">
              {{ item.waiter?.apelido || item.waiter?.name || '—' }}
              · {{ item.item_count }} ite{{ item.item_count !== 1 ? 'ns' : 'm' }}
            </span>
            <div class="cx-qcard__foot-right">
              <span
                class="cx-method-pill"
                :class="`cx-method-pill--${item.payment_method_chosen}`"
              >
                {{ methodLabels[item.payment_method_chosen] || '—' }}
              </span>
              <span class="cx-qcard__total">{{ formatPrice(item.total_amount) }}</span>
            </div>
          </div>
        </button>
      </div>

      <!-- ── Preview (somente desktop) ── -->
      <aside class="cx-queue__preview" v-if="isDesktop">
        <div v-if="!selected" class="cx-queue__preview-empty">
          Selecione uma mesa
        </div>
        <div v-else class="cx-preview">
          <div class="cx-preview__head">
            <div>
              <div class="cx-preview__mesa">
                {{ selected.type === 'entrega' ? '🛵 Entrega — ' + (selected.delivery?.customer_name || '') : 'Mesa ' + (selected.table?.number ?? '?') }}
              </div>
              <div
                class="cx-preview__guest"
                :class="{ 'cx-preview__guest--empty': !selected.guest_name }"
              >
                {{ selected.guest_name || 'Cliente não identificado' }}
              </div>
            </div>
            <span
              class="cx-qcard__wait-chip"
              :class="{ 'cx-qcard__wait-chip--overdue': selected.is_overdue }"
            >
              {{ formatWait(selected.wait_minutes) }}
            </span>
          </div>

          <dl class="cx-preview__meta">
            <div><dt>Garçom</dt><dd>{{ selected.waiter?.apelido || selected.waiter?.name || '—' }}</dd></div>
            <div><dt>Chegou</dt><dd>{{ formatTime(selected.at_cashier_at) }}</dd></div>
            <div><dt>Pedidos</dt><dd>{{ selected.order_count }} · {{ selected.item_count }} itens</dd></div>
            <div>
              <dt>Método</dt>
              <dd>
                <span
                  class="cx-method-pill"
                  :class="`cx-method-pill--${selected.payment_method_chosen}`"
                >
                  {{ methodLabels[selected.payment_method_chosen] || '—' }}
                </span>
              </dd>
            </div>
          </dl>

          <div class="cx-preview__total-block">
            <span class="cx-preview__total-label">Total a receber</span>
            <span class="cx-preview__total-value">{{ formatPrice(selected.total_amount) }}</span>
          </div>

          <button class="cx-btn cx-btn--primary cx-btn--block" @click="openSelected">
            Cobrar mesa →
          </button>
        </div>
      </aside>
    </div>
  </div>
</template>
