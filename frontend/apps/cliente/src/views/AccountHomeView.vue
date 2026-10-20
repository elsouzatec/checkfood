<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAccountStore } from '../stores/account'
import { useAuthStore } from '../stores/auth'
import { logoSrc } from '../links'
import QrScanner from '../components/QrScanner.vue'

var router = useRouter()
var account = useAccountStore()
var auth = useAuthStore()

var activeSession = ref(null)
var showScanner = ref(false)
var linking = ref(false)
var error = ref('')

onMounted(async function() {
  try {
    var data = await account.fetchMe()
    activeSession.value = data.active_session
  } catch (e) { /* 401 já leva ao login; demais erros: tela segue com o cache */ }
})

function openScanner() {
  error.value = ''
  showScanner.value = true
}

async function onScan(result) {
  showScanner.value = false
  if (result.type !== 'table') {
    error.value = 'Este QR Code não é de uma mesa. Escaneie o código que está na mesa.'
    return
  }
  linking.value = true
  try {
    await account.linkTable(result.token)
    router.push('/cardapio')
  } catch (err) {
    error.value = err.response?.data?.message || 'Não foi possível entrar na mesa. Tente novamente.'
  } finally {
    linking.value = false
  }
}

function onScanError(message) {
  showScanner.value = false
  error.value = message
}

function formatMoney(val) {
  return Number(val).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

async function logout() {
  await account.logout()
  router.replace('/')
}
</script>

<template>
  <div>
    <header class="acc-header">
      <img
        v-if="account.restaurant?.logo_url"
        :src="logoSrc(account.restaurant.logo_url)"
        :alt="account.restaurant.name"
        class="acc-header-logo"
      />
      <div class="acc-header-text">
        <div class="acc-header-restaurant">{{ account.restaurant?.name }}</div>
        <div class="acc-header-title">Olá, {{ account.firstName }}!</div>
      </div>
    </header>

    <main class="acc-page">
      <div v-if="error" class="msg msg-error">{{ error }}</div>
      <div v-if="linking" class="msg msg-warning">Entrando na mesa...</div>

      <!-- Mesa aberta neste aparelho -->
      <button
        v-if="activeSession && auth.isAuthenticated"
        class="acc-action acc-action--primary"
        @click="router.push('/cardapio')"
      >
        <span class="acc-action-icon">🍽️</span>
        <span class="acc-action-body">
          <span class="acc-action-title">Você está na Mesa {{ activeSession.table_number }}</span>
          <span class="acc-action-desc">Voltar ao cardápio e aos pedidos</span>
        </span>
        <span class="acc-action-arrow">→</span>
      </button>

      <button
        v-else
        class="acc-action acc-action--primary"
        :disabled="linking"
        @click="openScanner"
      >
        <span class="acc-action-icon">📱</span>
        <span class="acc-action-body">
          <span class="acc-action-title">Vincular a uma mesa</span>
          <span class="acc-action-desc">
            {{ activeSession ? 'Você tem a Mesa ' + activeSession.table_number + ' aberta — escaneie o QR para voltar' : 'Está no restaurante? Escaneie o QR Code da mesa' }}
          </span>
        </span>
        <span class="acc-action-arrow">→</span>
      </button>

      <button
        v-if="account.restaurant?.delivery?.enabled"
        class="acc-action"
        @click="router.push('/entrega')"
      >
        <span class="acc-action-icon">🛵</span>
        <span class="acc-action-body">
          <span class="acc-action-title">Pedir para entrega</span>
          <span class="acc-action-desc">
            Receba em casa{{ account.restaurant.delivery.eta_minutes ? ' · ~' + account.restaurant.delivery.eta_minutes + ' min' : '' }}{{ account.restaurant.delivery.fee > 0 ? ' · taxa ' + formatMoney(account.restaurant.delivery.fee) : ' · entrega grátis' }}
          </span>
        </span>
        <span class="acc-action-arrow">→</span>
      </button>

      <button class="acc-action" @click="router.push('/minha-conta/pedidos')">
        <span class="acc-action-icon">🧾</span>
        <span class="acc-action-body">
          <span class="acc-action-title">Meus pedidos</span>
          <span class="acc-action-desc">Histórico das suas visitas</span>
        </span>
        <span class="acc-action-arrow">→</span>
      </button>

      <div class="acc-section-title">Conta</div>

      <button class="acc-action" @click="router.push('/minha-conta/perfil')">
        <span class="acc-action-icon">👤</span>
        <span class="acc-action-body">
          <span class="acc-action-title">Meus dados</span>
          <span class="acc-action-desc">Nome, senha e exclusão da conta</span>
        </span>
        <span class="acc-action-arrow">→</span>
      </button>

      <button class="entry-link" @click="logout">Sair da conta</button>
    </main>

    <QrScanner v-if="showScanner" @result="onScan" @error="onScanError" @close="showScanner = false" />
  </div>
</template>
