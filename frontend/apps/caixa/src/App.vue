<script setup>
import { computed, ref, watch, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore }  from './stores/auth'
import { useShiftStore } from './stores/shift'
import api from './api'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const shift  = useShiftStore()

const showHeader = computed(() => auth.isAuthenticated && route.name !== 'login')

const firstName = computed(() => {
  const n = auth.user?.name || ''
  return n.split(' ')[0] || ''
})

const isQueueTab      = computed(() => ['queue', 'session'].includes(route.name))
const isHistoryTab    = computed(() => route.name === 'history')
const isFechamentoTab = computed(() => route.name === 'fechamento')
const isDeliveriesTab = computed(() => route.name === 'deliveries')

// Entregas novas aguardando aceite — contador na aba, visível de qualquer tela.
const newDeliveries = ref(0)
let deliveriesTimer = null

async function pollDeliveries() {
  try {
    const { data } = await api.get('/caixa/deliveries')
    newDeliveries.value = data.deliveries.filter(d => d.stage === 'aguardando' && d.status === 'active').length
  } catch { /* sem conexão: tenta no próximo ciclo */ }
}

watch(showHeader, (on) => {
  clearInterval(deliveriesTimer)
  if (on) {
    pollDeliveries()
    deliveriesTimer = setInterval(pollDeliveries, 30000)
  }
}, { immediate: true })

onUnmounted(() => clearInterval(deliveriesTimer))

function formatPrice(v) {
  return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatTime(iso) {
  if (!iso) return ''
  return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

const shiftSummary = computed(() => {
  const s = shift.shift
  if (!s) return null
  return {
    openedAt: formatTime(s.opened_at),
    total:    formatPrice(s.totals?.grand_total ?? 0),
  }
})

async function handleLogout() {
  await auth.logout()
  router.push('/')
}
</script>

<template>
  <div>
    <header v-if="showHeader" class="cx-header">
      <div class="cx-header__inner">
        <button class="cx-header__brand" @click="router.push('/fila')">
          <span class="cx-header__brand-logo" :title="auth.restaurant?.name || 'PagFood'">
            {{ auth.restaurant?.name || 'PagFood' }}
          </span>
          <span class="cx-header__brand-tag">Caixa</span>
        </button>

        <nav class="cx-header__tabs">
          <button
            class="cx-tab"
            :class="{ 'cx-tab--active': isQueueTab }"
            @click="router.push('/fila')"
          >
            Fila
          </button>
          <button
            class="cx-tab"
            :class="{ 'cx-tab--active': isDeliveriesTab }"
            @click="router.push('/entregas')"
          >
            Entregas
            <span v-if="newDeliveries > 0" class="cx-tab__badge">{{ newDeliveries }}</span>
          </button>
          <button
            class="cx-tab"
            :class="{ 'cx-tab--active': isHistoryTab }"
            @click="router.push('/historico')"
          >
            Histórico
          </button>
          <button
            class="cx-tab cx-tab--caixa"
            :class="{ 'cx-tab--active': isFechamentoTab }"
            @click="router.push('/fechamento')"
            title="Resumo do caixa e fechamento"
          >
            Caixa
          </button>
        </nav>

        <button
          v-if="shiftSummary"
          class="cx-header__shift"
          @click="router.push('/fechamento')"
          title="Ver resumo do caixa"
        >
          <span class="cx-header__shift-label">Turno</span>
          <span class="cx-header__shift-time">{{ shiftSummary.openedAt }}</span>
          <span class="cx-header__shift-sep">·</span>
          <span class="cx-header__shift-label">Recebido</span>
          <span class="cx-header__shift-total">{{ shiftSummary.total }}</span>
        </button>

        <div class="cx-header__right">
          <span v-if="firstName" class="cx-header__user" :title="auth.user?.name">
            {{ firstName }}
          </span>
          <button class="cx-header__logout" @click="handleLogout">Sair</button>
        </div>
      </div>
    </header>

    <router-view />
  </div>
</template>
