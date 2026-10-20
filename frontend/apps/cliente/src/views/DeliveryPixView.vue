<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import accountApi from '../accountApi'

// PIX pelo app de um pedido de ENTREGA. Usa os mesmos endpoints de pagamento
// da mesa (/payments/{id}); a confirmação vem do Mercado Pago (webhook).
var route = useRoute()
var router = useRouter()

var paymentId = route.params.paymentId
var deliveryId = route.query.entrega

var payment = ref(null)
var loading = ref(true)
var regenerating = ref(false)
var error = ref('')
var copied = ref(false)
var now = ref(Date.now())
var pollTimer = null
var clockTimer = null

var paid = computed(() => payment.value?.status === 'paid')
var expired = computed(() => {
  if (!payment.value?.expires_at) return false
  return new Date(payment.value.expires_at).getTime() <= now.value
})
var remaining = computed(() => {
  if (!payment.value?.expires_at) return ''
  var s = Math.max(0, Math.floor((new Date(payment.value.expires_at).getTime() - now.value) / 1000))
  return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0')
})

async function load() {
  try {
    var res = await accountApi.get('/payments/' + paymentId)
    payment.value = res.data.payment
    error.value = ''
    if (paid.value) clearInterval(pollTimer)
    // Sem QR (o Mercado Pago falhou ao criar no pedido): tenta gerar de novo.
    // Cobrança vencida não é regerada — a chave de idempotência devolveria a mesma.
    if (!paid.value && !payment.value.qr_code && !regenerating.value) await regenerate()
  } catch (e) {
    error.value = 'Não foi possível carregar o pagamento.'
  } finally {
    loading.value = false
  }
}

async function regenerate() {
  regenerating.value = true
  try {
    var res = await accountApi.post('/payments/' + paymentId + '/pay', { method: 'pix' })
    payment.value = res.data.payment
  } catch (e) {
    error.value = e.response?.data?.message || 'Não foi possível gerar o PIX. Tente de novo.'
  } finally {
    regenerating.value = false
  }
}

async function copyCode() {
  if (!payment.value?.qr_code) return
  try {
    await navigator.clipboard.writeText(payment.value.qr_code)
  } catch (e) {
    var el = document.createElement('textarea')
    el.value = payment.value.qr_code
    document.body.appendChild(el)
    el.select()
    document.execCommand('copy')
    document.body.removeChild(el)
  }
  copied.value = true
  setTimeout(function() { copied.value = false }, 2500)
}

function goOrders() {
  router.replace({ path: '/minha-conta/pedidos', query: deliveryId ? { entrega: deliveryId } : {} })
}

function formatMoney(val) {
  return Number(val).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

onMounted(function() {
  load()
  pollTimer = setInterval(load, 5000)
  clockTimer = setInterval(function() { now.value = Date.now() }, 1000)
})

onUnmounted(function() {
  clearInterval(pollTimer)
  clearInterval(clockTimer)
})
</script>

<template>
  <div>
    <header class="acc-header">
      <button class="acc-header-back" aria-label="Voltar" @click="goOrders">←</button>
      <div class="acc-header-text">
        <div class="acc-header-restaurant">🛵 Entrega</div>
        <div class="acc-header-title">Pagar com PIX</div>
      </div>
    </header>

    <main class="acc-page">
      <p v-if="loading" class="loading">Carregando...</p>
      <div v-if="error" class="msg msg-error">{{ error }}</div>

      <section v-if="payment && paid" class="card pix-card pix-done">
        <div class="pix-done-icon">✅</div>
        <h2>Pagamento confirmado!</h2>
        <p>O restaurante já recebeu seu pedido e vai começar a preparar.</p>
        <button class="btn btn-primary" @click="goOrders">Acompanhar pedido</button>
      </section>

      <section v-else-if="payment" class="card pix-card">
        <div class="pix-amount">{{ formatMoney(payment.amount) }}</div>
        <p class="pix-hint">Abra o app do seu banco, escolha PIX e escaneie o código ou use o copia e cola.</p>

        <p v-if="regenerating" class="loading">Gerando PIX...</p>
        <template v-else>
          <img
            v-if="payment.qr_code_base64 && !expired"
            :src="'data:image/png;base64,' + payment.qr_code_base64"
            alt="QR Code PIX"
            class="pix-qr"
          />

          <div v-if="payment.qr_code && !expired" class="pix-copy">
            <div class="pix-copy-label">PIX Copia e Cola</div>
            <div class="pix-copy-code">{{ payment.qr_code }}</div>
            <button class="btn btn-primary" @click="copyCode">{{ copied ? '✓ Copiado!' : '📋 Copiar código' }}</button>
          </div>

          <p v-if="payment.expires_at && !expired" class="pix-expiry">Expira em {{ remaining }}</p>
          <div v-if="expired" class="msg msg-warning">
            Este PIX expirou sem pagamento. Se você já pagou, aguarde a confirmação; senão,
            faça o pedido de novo ou fale com o restaurante.
          </div>
          <button v-if="!payment.qr_code" class="btn btn-primary" @click="regenerate">Tentar gerar o PIX de novo</button>
        </template>

        <p v-if="!expired" class="pix-wait">⏳ Aguardando a confirmação do pagamento — esta tela atualiza sozinha.</p>
      </section>

      <button class="entry-link" @click="goOrders">Ver meus pedidos</button>
    </main>
  </div>
</template>

<style scoped>
.pix-card { padding: 20px 16px; text-align: center; }
.pix-amount {
  font-size: 32px;
  font-weight: var(--fw-black);
  color: var(--text-primary);
}
.pix-hint { font-size: var(--fs-sm); color: var(--text-muted); margin: 6px 0 16px; line-height: 1.4; }
.pix-qr {
  width: 220px;
  height: 220px;
  margin: 0 auto 16px;
  display: block;
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius);
}
.pix-copy { text-align: left; }
.pix-copy-label { font-size: var(--fs-xs); font-weight: var(--fw-bold); color: var(--text-secondary); margin-bottom: 6px; }
.pix-copy-code {
  font-family: monospace;
  font-size: 12px;
  background: var(--surface-sunken);
  border-radius: var(--radius-sm);
  padding: 10px;
  word-break: break-all;
  margin-bottom: 10px;
  color: var(--text-primary);
}
.pix-expiry { font-size: var(--fs-sm); color: var(--text-muted); margin-top: 12px; }
.pix-wait {
  font-size: var(--fs-sm);
  color: var(--status-pending-color);
  background: var(--status-pending-bg);
  border-radius: var(--radius-sm);
  padding: 10px;
  margin-top: 16px;
}
.pix-done-icon { font-size: 44px; }
.pix-done h2 { font-size: var(--fs-xl); font-weight: var(--fw-bold); color: var(--text-primary); margin: 6px 0; }
.pix-done p { font-size: var(--fs-sm); color: var(--text-muted); margin-bottom: 16px; }
</style>
