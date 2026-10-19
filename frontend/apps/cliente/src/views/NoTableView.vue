<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import QrScanner from '../components/QrScanner.vue'

var router = useRouter()
var showScanner = ref(false)
var scanError = ref('')

// Último restaurante em que o cliente esteve (mesa ou conta) — atalho para
// criar a conta lá. A conta é por restaurante, então sem ele não há onde criar.
var lastRestaurant = null
try { lastRestaurant = JSON.parse(localStorage.getItem('pf_last_restaurant') || 'null') } catch (e) {}

function openScanner() {
  scanError.value = ''
  showScanner.value = true
}

function onScan(result) {
  showScanner.value = false
  router.push(result.path)
}

function onScanError(message) {
  showScanner.value = false
  scanError.value = message
}
</script>

<template>
  <div class="entry-screen">
    <div class="entry-brand">
      <div class="entry-logo">PagFood</div>
      <p class="entry-tagline">Cardápio digital e pedidos</p>
    </div>

    <div class="entry-card">
      <div class="entry-card-handle" aria-hidden="true"></div>

      <h2 class="entry-card-title">Bem-vindo!</h2>
      <p class="entry-card-sub">Está no restaurante? Escaneie o QR Code da mesa para pedir.</p>

      <div v-if="scanError" class="msg msg-error">{{ scanError }}</div>

      <button class="entry-submit-btn" @click="openScanner">📱 Escanear QR Code da mesa</button>
      <button class="entry-secondary-btn" @click="router.push('/entrar')">Entrar na minha conta</button>

      <button
        v-if="lastRestaurant?.slug"
        class="entry-link"
        @click="router.push({ path: '/cadastro', query: { r: lastRestaurant.slug } })"
      >
        Ainda não tem conta? <strong>Criar conta no {{ lastRestaurant.name }}</strong>
      </button>
      <p v-else class="entry-note">
        Para criar sua conta, escaneie o QR Code da mesa ou abra o link do restaurante.
      </p>
    </div>

    <QrScanner v-if="showScanner" @result="onScan" @error="onScanError" @close="showScanner = false" />
  </div>
</template>
