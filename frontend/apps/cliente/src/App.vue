<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import { useCartStore } from './stores/cart'
import api from './api'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const cart   = useCartStore()

const hasWaiter       = ref(false)
const callActive      = ref(false)
const callSecondsLeft = ref(0)
const callLoading     = ref(false)
let callTimer = null
let pollTimer = null

const showHeader = computed(() => {
  return auth.isAuthenticated
    && route.name !== 'qr-entry'
    && route.name !== 'no-table'
    && route.name !== 'feedback'
})

const showCallBtn = computed(() => hasWaiter.value && showHeader.value)

// Primeiro nome — helper local
const firstName = computed(() => {
  const n = auth.user?.name || ''
  return n.split(' ')[0] || ''
})

async function checkCallStatus() {
  if (!auth.isAuthenticated) return
  try {
    const res = await api.get('/waiter-calls/status')
    hasWaiter.value       = res.data.has_waiter
    callActive.value      = res.data.has_active_call
    callSecondsLeft.value = res.data.seconds_left || 0
    if (callActive.value && callSecondsLeft.value > 0) startCountdown()
  } catch (e) { /* ignore */ }
}

function startCountdown() {
  clearInterval(callTimer)
  callTimer = setInterval(() => {
    callSecondsLeft.value--
    if (callSecondsLeft.value <= 0) {
      callActive.value = false
      clearInterval(callTimer)
    }
  }, 1000)
}

async function callWaiter() {
  if (callActive.value || callLoading.value) return
  callLoading.value = true
  try {
    const res = await api.post('/waiter-calls')
    callActive.value      = true
    callSecondsLeft.value = res.data.seconds_left || 120
    startCountdown()
  } catch (err) {
    if (err.response && err.response.status === 429) {
      callActive.value      = true
      callSecondsLeft.value = err.response.data.seconds_left || 60
      startCountdown()
    } else {
      alert(err.response?.data?.message || 'Erro ao chamar garçom.')
    }
  } finally {
    callLoading.value = false
  }
}

onMounted(() => {
  if (auth.isAuthenticated) {
    checkCallStatus()
    pollTimer = setInterval(checkCallStatus, 15000)
  }
})

onUnmounted(() => {
  clearInterval(callTimer)
  clearInterval(pollTimer)
})
</script>

<template>
  <div>
    <!-- Header 3 colunas: Empresa · Mesa · Nome -->
    <header v-if="showHeader" class="app-header app-header-3col">
      <div class="header-brand" :title="auth.restaurant?.name || 'PagFood'">
        {{ auth.restaurant?.name || 'PagFood' }}
      </div>

      <div class="header-center">
        <span v-if="auth.tableNumber" class="header-badge">
          Mesa {{ auth.tableNumber }}
        </span>
      </div>

      <div class="header-right">
        <span v-if="firstName" class="header-user-name" :title="auth.user?.name">
          {{ firstName }}
        </span>
      </div>
    </header>

    <nav v-if="showHeader" class="tabs-nav">
      <router-link to="/cardapio" class="tab" :class="{ active: route.name === 'menu' }">
        Cardápio
      </router-link>
      <router-link to="/carrinho" class="tab" :class="{ active: route.name === 'cart' }">
        Carrinho
        <span v-if="cart.itemCount > 0" class="tab-badge">{{ cart.itemCount }}</span>
      </router-link>
      <router-link to="/pedidos" class="tab" :class="{ active: route.name === 'orders' }">
        Pedidos
      </router-link>
    </nav>

    <router-view />

    <!-- Floating call waiter button -->
    <div
      v-if="showCallBtn"
      class="call-waiter-float"
      :class="{ 'call-active': callActive }"
      @click="callWaiter"
    >
      <template v-if="callLoading">
        <span class="call-icon">⏳</span>
      </template>
      <template v-else-if="callActive">
        <span class="call-icon call-pulse">🔔</span>
        <span class="call-timer">
          {{ Math.floor(callSecondsLeft / 60) }}:{{ String(callSecondsLeft % 60).padStart(2, '0') }}
        </span>
      </template>
      <template v-else>
        <span class="call-icon">🙋</span>
        <span class="call-label">Chamar Garçom</span>
      </template>
    </div>
  </div>
</template>

<style>
/* Float de chamar garçom — mantido na view pois é específico do cliente */
.call-waiter-float {
  position: fixed;
  bottom: 24px;
  right: 20px;
  background: var(--surface);
  border: 2px solid var(--brand-accent);
  border-radius: var(--radius-pill);
  padding: 10px 18px;
  display: flex;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  z-index: 45;
  box-shadow: 0 4px 16px rgba(221, 111, 45, 0.22);
  transition: transform var(--dur-fast), background var(--dur-normal);
  touch-action: manipulation;
  user-select: none;
}
.call-waiter-float:active { transform: scale(0.95); }
.call-waiter-float.call-active {
  background: var(--brand-accent);
  color: #FFFFFF;
}
.call-waiter-float.call-active .call-label,
.call-waiter-float.call-active .call-timer { color: #FFFFFF; }

.call-icon { font-size: 20px; line-height: 1; }
.call-label { font-size: var(--fs-sm); font-weight: var(--fw-bold); color: var(--brand-accent); }
.call-timer { font-size: var(--fs-sm); font-weight: var(--fw-bold); font-variant-numeric: tabular-nums; }

.call-pulse { animation: bellPulse 1s ease-in-out infinite; }
@keyframes bellPulse {
  0%, 100% { transform: rotate(0deg); }
  25% { transform: rotate(15deg); }
  75% { transform: rotate(-15deg); }
}
</style>
