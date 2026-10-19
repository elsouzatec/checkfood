<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import { useAccountStore } from '../stores/account'
import { logoSrc } from '../links'
import QrScanner from '../components/QrScanner.vue'

// Link do restaurante (pagfood.online/r/{slug}) — o que ele divulga no
// Instagram/WhatsApp. Porta de entrada da conta, que é por restaurante.
var route = useRoute()
var router = useRouter()
var account = useAccountStore()

var slug = route.params.slug
var restaurant = ref(null)
var loading = ref(true)
var notFound = ref(false)
var showScanner = ref(false)
var scanError = ref('')

onMounted(async function() {
  if (account.isLoggedIn && account.restaurant?.slug === slug) {
    router.replace('/minha-conta')
    return
  }
  try {
    var res = await api.get('/restaurants/by-slug/' + encodeURIComponent(slug))
    restaurant.value = res.data.restaurant
    localStorage.setItem('pf_last_restaurant', JSON.stringify(res.data.restaurant))
  } catch (err) {
    notFound.value = true
  } finally {
    loading.value = false
  }
})

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
      <template v-if="restaurant">
        <img v-if="restaurant.logo_url" :src="logoSrc(restaurant.logo_url)" :alt="restaurant.name" class="entry-logo-img" />
        <div class="entry-restaurant-name">{{ restaurant.name }}</div>
        <p class="entry-tagline">Peça pelo celular e acompanhe seus pedidos</p>
      </template>
      <div v-else class="entry-logo">PagFood</div>
    </div>

    <div class="entry-card">
      <div class="entry-card-handle" aria-hidden="true"></div>

      <p v-if="loading" class="loading">Carregando...</p>

      <template v-else-if="notFound">
        <h2 class="entry-card-title">Restaurante não encontrado</h2>
        <p class="entry-card-sub">Confira o link com o restaurante ou escaneie o QR Code da mesa.</p>
        <button class="entry-secondary-btn" @click="router.push('/')">Ir para o início</button>
      </template>

      <template v-else>
        <h2 class="entry-card-title">Sua conta no {{ restaurant.name }}</h2>
        <p class="entry-card-sub">Com a conta você entra na mesa com um toque e guarda o histórico dos seus pedidos.</p>

        <div v-if="scanError" class="msg msg-error">{{ scanError }}</div>

        <button class="entry-submit-btn" @click="router.push({ path: '/cadastro', query: { r: slug } })">Criar conta</button>
        <button class="entry-secondary-btn" @click="router.push({ path: '/entrar', query: { r: slug } })">Já tenho conta — entrar</button>
        <button class="entry-link" @click="scanError = ''; showScanner = true">
          Está no restaurante? <strong>Escanear QR Code da mesa</strong>
        </button>
      </template>
    </div>

    <QrScanner v-if="showScanner" @result="onScan" @error="onScanError" @close="showScanner = false" />
  </div>
</template>
