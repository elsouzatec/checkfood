<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useShiftStore } from '../stores/shift'
import api from '../api'

const route  = useRoute()
const router = useRouter()
const shift  = useShiftStore()

const session     = ref(null)
const loading     = ref(true)
const fetchError  = ref('')

const payAmount   = ref('')
const payMethod   = ref('')
const payNotes    = ref('')
const payLoading  = ref(false)
const payError    = ref('')
const successMsg  = ref('')
const fullyPaid   = ref(false)

const pixData     = ref(null)
const pixLoading  = ref(false)
const pixError    = ref('')
const pixPollInterval = ref(null)

const methods = [
  { key: 'pix',        label: 'PIX' },
  { key: 'dinheiro',   label: 'Dinheiro' },
  { key: 'cartao',     label: 'Cartão' },
  { key: 'maquininha', label: 'Maquininha' },
]

const methodLabels = {
  pix: 'PIX', dinheiro: 'Dinheiro', cartao: 'Cartão', maquininha: 'Maquininha',
}

const isClosed    = computed(() => session.value?.status === 'closed')
const remaining   = computed(() => session.value?.remaining_amount ?? 0)
const showPix     = computed(() => !!pixData.value)
const fromHistory = computed(() => route.query.origem === 'historico')

function goBack() {
  if (fromHistory.value) router.push('/historico')
  else router.push('/fila')
}

const enteredAmount = computed(() =>
  parseFloat(String(payAmount.value).replace(',', '.')) || 0
)
const troco = computed(() => {
  if (payMethod.value !== 'dinheiro') return 0
  const diff = enteredAmount.value - remaining.value
  return diff > 0.005 ? diff : 0
})

function formatPrice(v) {
  return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

async function fetchSession() {
  loading.value    = true
  fetchError.value = ''
  try {
    const { data } = await api.get(`/caixa/sessions/${route.params.id}`)
    session.value  = data
    fullyPaid.value = data.status === 'closed'
    payAmount.value = data.remaining_amount > 0
      ? String(data.remaining_amount.toFixed(2)).replace('.', ',')
      : ''
    if (!payMethod.value && data.payment_method_chosen) {
      payMethod.value = data.payment_method_chosen
    }
  } catch (err) {
    fetchError.value = err.response?.data?.message || 'Erro ao carregar sessão.'
  } finally {
    loading.value = false
  }
}

async function generatePix() {
  pixLoading.value = true
  pixError.value   = ''
  try {
    const { data } = await api.post(`/caixa/sessions/${route.params.id}/pix`)
    pixData.value   = data
    startPixPolling(data.payment_id)
  } catch (err) {
    pixError.value = err.response?.data?.message || 'Erro ao gerar PIX.'
  } finally {
    pixLoading.value = false
  }
}

function startPixPolling(paymentId) {
  stopPixPolling()
  pixPollInterval.value = setInterval(async () => {
    try {
      const { data } = await api.get(`/payments/${paymentId}`)
      if (data.payment.status === 'paid') {
        stopPixPolling()
        await fetchSession()
        try { await shift.fetch() } catch { /* silencia */ }
        if (session.value?.status === 'closed') {
          fullyPaid.value  = true
          successMsg.value = 'PIX confirmado! Sessão encerrada.'
          pixData.value    = null
          setTimeout(() => router.push('/fila'), 2000)
        } else {
          successMsg.value = `PIX confirmado (${formatPrice(data.payment.amount)}). Restante: ${formatPrice(session.value.remaining_amount)}`
          pixData.value    = null
          payAmount.value  = String(session.value.remaining_amount.toFixed(2)).replace('.', ',')
        }
      }
    } catch { /* silencia polling */ }
  }, 3000)
}

function stopPixPolling() {
  if (pixPollInterval.value) {
    clearInterval(pixPollInterval.value)
    pixPollInterval.value = null
  }
}

async function cancelPix() {
  stopPixPolling()
  try {
    await api.post(`/caixa/sessions/${route.params.id}/pix/cancel`)
  } catch (err) {
    console.warn('Falha ao cancelar PIX:', err?.response?.data || err)
  }
  pixData.value  = null
  pixError.value = ''
}

function copyPixCode() {
  if (pixData.value?.qr_code) {
    navigator.clipboard.writeText(pixData.value.qr_code)
  }
}

function selectMethod(key) {
  payMethod.value = key
  payError.value  = ''
}

async function confirmPayment() {
  if (payMethod.value === 'pix') {
    await generatePix()
    return
  }

  if (!payMethod.value) {
    payError.value = 'Selecione o meio de pagamento.'
    return
  }

  if (!enteredAmount.value || enteredAmount.value <= 0) {
    payError.value = 'Informe um valor válido.'
    return
  }

  const amountToRegister = troco.value > 0 ? remaining.value : enteredAmount.value

  payLoading.value = true
  payError.value   = ''
  successMsg.value = ''

  try {
    const { data } = await api.post(`/caixa/sessions/${route.params.id}/confirm-payment`, {
      amount: amountToRegister,
      method: payMethod.value,
      notes:  payNotes.value || undefined,
    })

    session.value.paid_amount      = data.session.paid_amount
    session.value.remaining_amount = data.session.remaining_amount
    session.value.status           = data.session.status

    try { await shift.fetch() } catch { /* silencia */ }

    if (data.session.fully_paid) {
      fullyPaid.value  = true
      successMsg.value = 'Pagamento confirmado! Sessão encerrada.'
      session.value.payments_done.push(data.payment)
      setTimeout(() => router.push('/fila'), 2000)
    } else {
      successMsg.value = `Recebido ${formatPrice(data.payment.amount)} via ${methodLabels[data.payment.method]}. Restante: ${formatPrice(data.session.remaining_amount)}`
      payAmount.value  = String(data.session.remaining_amount.toFixed(2)).replace('.', ',')
      payNotes.value   = ''
      session.value.payments_done.push(data.payment)
    }
  } catch (err) {
    payError.value = err.response?.data?.message || 'Erro ao confirmar pagamento.'
  } finally {
    payLoading.value = false
  }
}

onMounted(fetchSession)
onUnmounted(stopPixPolling)
</script>

<template>
  <div class="cx-session">
    <div v-if="loading" class="cx-session__loading">Carregando sessão...</div>

    <div v-else-if="fetchError" class="cx-session__error">
      <p>{{ fetchError }}</p>
      <button class="cx-btn cx-btn--secondary" @click="router.push('/fila')">Voltar à fila</button>
    </div>

    <div v-else class="cx-session__layout">
      <!-- ═══════════ COLUNA ESQUERDA: Detalhes ═══════════ -->
      <div class="cx-session__detail">
        <button class="cx-session__back" @click="goBack">
          ← {{ fromHistory ? 'Histórico' : 'Fila' }}
        </button>

        <header class="cx-session__head">
          <div class="cx-session__head-id">
            <h1 class="cx-session__mesa">
              {{ session.table?.name || `Mesa ${session.table?.number}` }}
            </h1>
            <span
              v-if="session.guest_name"
              class="cx-session__guest"
            >{{ session.guest_name }}</span>
            <span v-else class="cx-session__guest cx-session__guest--empty">
              Cliente não identificado
            </span>
          </div>
          <div class="cx-session__head-meta">
            <span>Garçom: <strong>{{ session.waiter?.apelido || session.waiter?.name || '—' }}</strong></span>
            <span>Chegou às <strong>{{ formatTime(session.at_cashier_at) }}</strong></span>
            <span
              v-if="session.payment_method_chosen"
              class="cx-method-pill"
              :class="`cx-method-pill--${session.payment_method_chosen}`"
            >{{ methodLabels[session.payment_method_chosen] }}</span>
          </div>
        </header>

        <!-- Totais — só no mobile (no desktop ficam no painel direito) -->
        <section class="cx-session__totals cx-session__totals--mobile">
          <div class="cx-totals-row">
            <span>Total</span>
            <strong>{{ formatPrice(session.total_amount) }}</strong>
          </div>
          <div v-if="session.paid_amount > 0" class="cx-totals-row cx-totals-row--muted">
            <span>Pago</span>
            <strong>{{ formatPrice(session.paid_amount) }}</strong>
          </div>
          <div
            class="cx-totals-row cx-totals-row--accent"
            :class="{ 'cx-totals-row--done': isClosed }"
          >
            <span>{{ isClosed ? 'Quitado' : 'Restante' }}</span>
            <strong>{{ isClosed ? '✓' : formatPrice(session.remaining_amount) }}</strong>
          </div>
        </section>

        <!-- Pedidos -->
        <section class="cx-section">
          <h2 class="cx-section__title">Pedidos</h2>
          <div v-for="order in session.orders" :key="order.id" class="cx-order">
            <div class="cx-order__head">
              <span class="cx-order__id">#{{ order.id }}</span>
              <span class="cx-order__time">{{ formatTime(order.created_at) }}</span>
              <span class="cx-order__total">{{ formatPrice(order.total) }}</span>
            </div>
            <ul class="cx-order__items">
              <li v-for="item in order.items" :key="item.id" class="cx-order__item">
                <span class="cx-order__qty">{{ item.quantity }}×</span>
                <span class="cx-order__name">{{ item.name }}</span>
                <span class="cx-order__subtotal">{{ formatPrice(item.subtotal) }}</span>
                <div v-if="item.notes" class="cx-order__notes">⚠ {{ item.notes }}</div>
              </li>
            </ul>
          </div>
        </section>

        <!-- Recebimentos parciais -->
        <section v-if="session.payments_done?.length > 0" class="cx-section">
          <h2 class="cx-section__title">Recebimentos</h2>
          <div class="cx-payments-list">
            <div v-for="p in session.payments_done" :key="p.id" class="cx-payment-chip">
              <span class="cx-method-pill" :class="`cx-method-pill--${p.method}`">
                {{ methodLabels[p.method] || p.method }}
              </span>
              <span class="cx-payment-chip__amount">{{ formatPrice(p.amount) }}</span>
            </div>
          </div>
        </section>
      </div>

      <!-- ═══════════ COLUNA DIREITA: Cobrança ═══════════ -->
      <aside class="cx-session__pay">
        <div class="cx-pay-card">
          <!-- Banner: histórico (somente revisão) -->
          <div v-if="fromHistory" class="cx-review-banner">
            <span>Sessão encerrada — modo conferência</span>
          </div>

          <!-- Sucesso -->
          <div v-else-if="fullyPaid" class="cx-pay-success">
            <div class="cx-pay-success__icon">✓</div>
            <div class="cx-pay-success__title">Pagamento concluído</div>
            <p class="cx-pay-success__text">Voltando para a fila...</p>
          </div>

          <!-- PIX modal -->
          <div v-else-if="showPix" class="cx-pix">
            <div class="cx-pix__title">QR Code PIX</div>
            <div class="cx-pix__amount">{{ formatPrice(pixData.amount) }}</div>

            <div v-if="pixData.qr_code_base64" class="cx-pix__qr">
              <img
                :src="`data:image/png;base64,${pixData.qr_code_base64}`"
                alt="QR Code PIX"
              />
            </div>

            <div class="cx-pix__copy">
              <input :value="pixData.qr_code" readonly />
              <button class="cx-btn cx-btn--secondary" @click="copyPixCode">Copiar</button>
            </div>

            <div class="cx-pix__hint">
              <span class="cx-spinner"></span>
              Aguardando confirmação (3s)
            </div>

            <button class="cx-btn cx-btn--secondary cx-btn--block" @click="cancelPix">
              Cancelar / Usar outro método
            </button>
          </div>

          <!-- Form de pagamento -->
          <template v-else>
            <!-- Totais — desktop -->
            <div class="cx-pay-card__totals">
              <div class="cx-totals-row">
                <span>Total</span>
                <strong>{{ formatPrice(session.total_amount) }}</strong>
              </div>
              <div v-if="session.paid_amount > 0" class="cx-totals-row cx-totals-row--muted">
                <span>Pago</span>
                <strong>{{ formatPrice(session.paid_amount) }}</strong>
              </div>
              <div class="cx-totals-row cx-totals-row--accent">
                <span>Restante</span>
                <strong class="cx-totals-row__big">{{ formatPrice(session.remaining_amount) }}</strong>
              </div>
            </div>

            <h2 class="cx-section__title cx-section__title--in-card">Confirmar recebimento</h2>

            <div v-if="successMsg" class="cx-msg cx-msg--success">{{ successMsg }}</div>
            <div v-if="payError"   class="cx-msg cx-msg--error">{{ payError }}</div>
            <div v-if="pixError"   class="cx-msg cx-msg--error">{{ pixError }}</div>

            <div class="cx-pay-methods">
              <button
                v-for="m in methods"
                :key="m.key"
                class="cx-pay-method"
                :class="{ 'cx-pay-method--active': payMethod === m.key }"
                :disabled="payLoading || pixLoading"
                @click="selectMethod(m.key)"
              >
                {{ m.label }}
              </button>
            </div>

            <div v-if="payMethod && payMethod !== 'pix'" class="cx-field">
              <label>Valor recebido (R$)</label>
              <input
                v-model="payAmount"
                type="text"
                inputmode="decimal"
                placeholder="0,00"
                class="cx-input cx-input--xl"
                :disabled="payLoading"
                @keyup.enter="confirmPayment"
              />
            </div>

            <div v-if="payMethod && payMethod !== 'pix'" class="cx-field">
              <label>Observações <span class="cx-field__optional">(opcional)</span></label>
              <input
                v-model="payNotes"
                type="text"
                class="cx-input"
                placeholder="Troco, split, etc."
                :disabled="payLoading"
                maxlength="255"
              />
            </div>

            <div v-if="troco > 0" class="cx-troco">
              <span class="cx-troco__label">Troco</span>
              <span class="cx-troco__value">{{ formatPrice(troco) }}</span>
            </div>

            <button
              class="cx-btn cx-btn--primary cx-btn--block cx-btn--lg cx-pay-confirm"
              :disabled="payLoading || pixLoading || !payMethod"
              @click="confirmPayment"
            >
              <span v-if="payLoading || pixLoading" class="cx-spinner"></span>
              <span v-if="payMethod === 'pix'">
                {{ pixLoading ? 'Gerando QR Code...' : 'Gerar QR Code PIX' }}
              </span>
              <span v-else-if="troco > 0">
                Registrar (troco {{ formatPrice(troco) }})
              </span>
              <span v-else>
                {{ payLoading ? 'Confirmando...' : 'Confirmar Recebimento' }}
              </span>
            </button>
          </template>
        </div>
      </aside>
    </div>
  </div>
</template>
