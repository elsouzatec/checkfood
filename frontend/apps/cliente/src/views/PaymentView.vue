<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router  = useRouter()
const loading = ref(true)
const error   = ref('')
const step    = ref('loading')
const payment = ref(null)
const actionLoading = ref(false)
let pollInterval = null

/**
 * Salva session_id em pf_feedback_session antes de qualquer redirect ao
 * /feedback. Sem isso, o FeedbackView cai em "Nenhuma visita encontrada"
 * quando o cliente chega ao feedback por um caminho que não passou pelo
 * scheduleRedirect (F5 na tela de pagamento já encerrada, por exemplo).
 */
function preserveFeedbackSession() {
  try {
    const stored = localStorage.getItem('pf_session')
    if (stored) {
      const parsed = JSON.parse(stored)
      if (parsed?.id) {
        localStorage.setItem('pf_feedback_session', String(parsed.id))
      }
    }
  } catch (e) { /* ignora */ }
}

async function init() {
  try {
    const { data } = await api.get('/sessions/check')

    if (data.session_status !== 'closing' || !data.payment_id) {
      router.replace('/pedidos')
      return
    }

    const { data: payData } = await api.get(`/payments/${data.payment_id}`)
    payment.value = payData.payment

    if (payment.value.status === 'paid') {
      step.value = 'done'
      scheduleRedirect()
      return
    }

    if (payment.value.method === 'pix' && payment.value.qr_code) {
      step.value = 'pix'
      startPolling()
    } else {
      step.value = 'methods'
    }
  } catch (err) {
    if (err.response?.status === 410) {
      // Sessão já foi encerrada por outro ator.
      // Preserva session_id para o FeedbackView conseguir localizar.
      preserveFeedbackSession()
      router.replace('/feedback')
    } else {
      error.value = 'Erro ao carregar informações de pagamento.'
      step.value  = 'error'
    }
  } finally {
    loading.value = false
  }
}

async function selectMethod(method) {
  if (!payment.value || actionLoading.value) return
  actionLoading.value = true
  error.value = ''
  try {
    const { data } = await api.post(`/payments/${payment.value.id}/pay`, { method })
    payment.value = data.payment
    if (method === 'pix') {
      step.value = 'pix'
      startPolling()
    } else {
      step.value = 'done'
      scheduleRedirect()
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao processar pagamento.'
  } finally {
    actionLoading.value = false
  }
}

async function copyPixCode() {
  if (!payment.value?.qr_code) return
  try {
    await navigator.clipboard.writeText(payment.value.qr_code)
  } catch {
    const el = document.createElement('textarea')
    el.value = payment.value.qr_code
    document.body.appendChild(el); el.select()
    document.execCommand('copy')
    document.body.removeChild(el)
  }
  alert('Código Pix copiado!')
}

function startPolling() {
  stopPolling()
  pollInterval = setInterval(async () => {
    try {
      const { data } = await api.get(`/payments/${payment.value.id}`)
      payment.value = data.payment
      const status = data.payment.status

      // Fluxo feliz — pagamento confirmado
      if (status === 'paid') {
        stopPolling()
        step.value = 'done'
        scheduleRedirect()
        return
      }

      // Pagamento morreu no gateway — volta à seleção de método
      if (status === 'expired' || status === 'failed') {
        stopPolling()
        error.value = status === 'expired'
          ? 'O código Pix expirou. Escolha o método novamente.'
          : 'Pagamento não autorizado. Tente novamente.'
        step.value = 'methods'
        return
      }

      // 'pending' ou outros estados intermediários → continua pollando
    } catch (err) {
      const httpStatus = err.response?.status

      // Pagamento sumiu do backend (cancelamento administrativo, etc.)
      if (httpStatus === 404) {
        stopPolling()
        error.value = 'Pagamento não encontrado.'
        step.value = 'error'
        return
      }

      // Sessão encerrada por outro ator — preserva e vai pro feedback
      if (httpStatus === 410) {
        stopPolling()
        preserveFeedbackSession()
        router.replace('/feedback')
        return
      }

      // Token revogado — deixa o interceptor global do axios tratar
      if (httpStatus === 401) {
        stopPolling()
        return
      }

      // Erros transitórios (5xx, timeout, offline) → ignora e tenta no próximo tick
    }
  }, 5000)
}

function stopPolling() {
  if (pollInterval) { clearInterval(pollInterval); pollInterval = null }
}

function scheduleRedirect() {
  setTimeout(() => {
    // Preserva session_id para o FeedbackView
    preserveFeedbackSession()

    // NÃO limpa o token aqui — FeedbackView.finish() cuida disso
    // Limpa apenas dados de sessão/mesa/carrinho
    localStorage.removeItem('pf_session')
    localStorage.removeItem('pf_table')
    localStorage.removeItem('pf_orders')

    router.replace('/feedback')
  }, 3000)
}

function formatMoney(val) {
  return Number(val).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function expiryTime() {
  if (!payment.value?.expires_at) return ''
  return new Date(payment.value.expires_at)
    .toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

onMounted(init)
onUnmounted(stopPolling)
</script>

<template>
  <div class="pay-screen">

    <!-- ── Loading ── -->
    <div v-if="loading" class="pay-center">
      <div class="pay-spinner"></div>
      <p>Carregando...</p>
    </div>

    <!-- ── Erro ── -->
    <div v-else-if="step === 'error'" class="pay-center">
      <div class="pay-icon">⚠️</div>
      <p class="pay-error-msg">{{ error }}</p>
      <button class="pay-btn" @click="router.push('/pedidos')">Voltar</button>
    </div>

    <!-- ── Seleção de método ── -->
    <div v-else-if="step === 'methods'" class="pay-content">
      <div class="pay-header">
        <div class="pay-logo">PagFood</div>
        <div class="pay-section-title">Hora de pagar 🎉</div>
      </div>

      <div v-if="error" class="error-banner">{{ error }}</div>

      <div class="pay-total-card">
        <div class="pay-total-label">Total da conta</div>
        <div class="pay-total-value">{{ formatMoney(payment?.amount || 0) }}</div>
      </div>

      <div class="methods-title">Como deseja pagar?</div>

      <div class="method-list">
        <button
          class="method-item"
          :disabled="actionLoading"
          @click="selectMethod('pix')"
        >
          <span class="method-emoji">📱</span>
          <div class="method-info">
            <div class="method-name">Pix</div>
            <div class="method-desc">QR Code — instantâneo</div>
          </div>
          <span class="method-arrow">›</span>
        </button>

        <div class="method-divider"></div>

        <div class="method-note">
          Prefere pagar de outra forma? Chame o garçom. 😊
        </div>
      </div>
    </div>

    <!-- ── QR Code Pix ── -->
    <div v-else-if="step === 'pix'" class="pay-content">
      <div class="pay-header">
        <div class="pay-logo">PagFood</div>
        <div class="pay-section-title">Pix — Escaneie o QR Code</div>
      </div>

      <div class="pay-total-card">
        <div class="pay-total-label">Valor a pagar</div>
        <div class="pay-total-value">{{ formatMoney(payment.amount) }}</div>
      </div>

      <div v-if="payment.qr_code_base64" class="qr-container">
        <img
          :src="'data:image/png;base64,' + payment.qr_code_base64"
          alt="QR Code Pix"
          class="qr-img"
        />
      </div>

      <div v-if="payment.qr_code" class="copy-box">
        <div class="copy-label">Pix Copia e Cola</div>
        <div class="copy-code">{{ payment.qr_code }}</div>
        <button class="btn-copy" @click="copyPixCode">📋 Copiar código</button>
      </div>

      <div class="waiting-status">
        <span class="status-dot"></span>
        Aguardando pagamento...
      </div>

      <div v-if="payment.expires_at" class="expiry-info">
        Código válido até {{ expiryTime() }}
      </div>
    </div>

    <!-- ── Confirmado ── -->
    <div v-else-if="step === 'done'" class="pay-center pay-done">
      <div class="done-checkmark">✅</div>
      <div class="done-title">Pagamento confirmado!</div>
      <div class="done-msg">Obrigado pela visita. Até logo! 👋</div>
      <div class="done-redirect">Redirecionando para avaliação...</div>
    </div>

  </div>
</template>

<style scoped>
/* ============================================================
   PaymentView — mesma gramática visual do KDS (verde profundo),
   acentos em laranja torrado, sucesso em teal. Tudo via tokens.
============================================================ */

.pay-screen {
  min-height: 100vh;
  background: var(--kds-bg);
  color: #FFFFFF;
  display: flex;
  flex-direction: column;
}

.pay-center {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 24px;
  gap: 16px;
  text-align: center;
  color: rgba(255, 255, 255, 0.8);
}

.pay-icon { font-size: 48px; line-height: 1; }

.pay-error-msg {
  color: rgba(255, 255, 255, 0.85);
  font-size: var(--fs-md);
  line-height: 1.4;
}

.pay-btn {
  background: var(--brand-accent);
  color: var(--brand-accent-text);
  padding: 12px 24px;
  border: none;
  border-radius: var(--radius-sm);
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  cursor: pointer;
  font-family: inherit;
  transition: background var(--dur-normal), transform var(--dur-fast);
}
.pay-btn:hover  { background: var(--brand-accent-hover); }
.pay-btn:active { transform: scale(0.97); }

.pay-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  padding: 24px 20px 40px;
  max-width: 420px;
  margin: 0 auto;
  width: 100%;
}

/* ── Cabeçalho ── */
.pay-header { margin-bottom: 24px; }
.pay-logo {
  font-size: 24px;
  font-weight: var(--fw-black);
  color: var(--brand-accent);
  margin-bottom: 4px;
  letter-spacing: -0.3px;
}
.pay-section-title {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: #FFFFFF;
}

/* ── Card do total ── */
.pay-total-card {
  background: rgba(255, 255, 255, 0.07);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: var(--radius-lg);
  padding: 22px 20px;
  text-align: center;
  margin-bottom: 24px;
}
.pay-total-label {
  font-size: var(--fs-xs);
  color: rgba(255, 255, 255, 0.55);
  margin-bottom: 4px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  font-weight: var(--fw-semibold);
}
.pay-total-value {
  font-size: 36px;
  font-weight: var(--fw-black);
  color: #FFFFFF;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.5px;
}

/* ── Banner de erro inline ── */
.error-banner {
  background: rgba(230, 57, 70, 0.22);
  border: 1px solid rgba(230, 57, 70, 0.45);
  color: #FFB3B9;
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  margin-bottom: 16px;
}

/* ── Lista de métodos ── */
.methods-title {
  font-size: var(--fs-sm);
  color: rgba(255, 255, 255, 0.55);
  margin-bottom: 12px;
  font-weight: var(--fw-semibold);
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.method-list {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: var(--radius-lg);
  overflow: hidden;
}
.method-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px;
  background: none;
  border: none;
  color: #FFFFFF;
  cursor: pointer;
  font-family: inherit;
  transition: background var(--dur-fast);
}
.method-item:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.07);
}
.method-item:disabled { opacity: 0.5; cursor: not-allowed; }
.method-emoji { font-size: 28px; line-height: 1; }
.method-info { flex: 1; text-align: left; min-width: 0; }
.method-name {
  font-size: 15px;
  font-weight: var(--fw-bold);
  color: #FFFFFF;
}
.method-desc {
  font-size: var(--fs-xs);
  color: rgba(255, 255, 255, 0.55);
  margin-top: 2px;
}
.method-arrow {
  font-size: 22px;
  color: rgba(255, 255, 255, 0.35);
  font-weight: var(--fw-bold);
}
.method-divider {
  height: 1px;
  background: rgba(255, 255, 255, 0.08);
}
.method-note {
  padding: 14px 16px;
  font-size: var(--fs-sm);
  color: rgba(255, 255, 255, 0.5);
}

/* ── QR Code Pix ── */
.qr-container {
  display: flex;
  justify-content: center;
  margin-bottom: 20px;
}
.qr-img {
  width: 220px;
  height: 220px;
  border-radius: var(--radius);
  background: #FFFFFF;
  padding: 10px;
}

.copy-box {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: var(--radius);
  padding: 14px;
  margin-bottom: 16px;
}
.copy-label {
  font-size: var(--fs-xs);
  color: rgba(255, 255, 255, 0.45);
  margin-bottom: 6px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
  font-weight: var(--fw-semibold);
}
.copy-code {
  font-size: 11px;
  word-break: break-all;
  color: rgba(255, 255, 255, 0.75);
  margin-bottom: 10px;
  line-height: 1.5;
  max-height: 60px;
  overflow: hidden;
  font-family: monospace;
}
.btn-copy {
  width: 100%;
  background: var(--brand-accent);
  border: none;
  color: var(--brand-accent-text);
  padding: 10px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  cursor: pointer;
  font-family: inherit;
  transition: background var(--dur-normal), transform var(--dur-fast);
}
.btn-copy:hover  { background: var(--brand-accent-hover); }
.btn-copy:active { transform: scale(0.98); }

/* ── Status / rodapé ── */
.waiting-status {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: rgba(255, 255, 255, 0.65);
  font-size: var(--fs-sm);
  margin-bottom: 8px;
  font-weight: var(--fw-semibold);
}
.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: var(--status-ready-color);
  animation: blink 1.5s ease-in-out infinite;
}
@keyframes blink {
  0%, 100% { opacity: 1; }
  50%      { opacity: 0.2; }
}

.expiry-info {
  text-align: center;
  font-size: var(--fs-xs);
  color: rgba(255, 255, 255, 0.35);
}

/* ── Tela de confirmação ── */
.pay-done { gap: 12px; }
.done-checkmark { font-size: 64px; line-height: 1; }
.done-title {
  font-size: 22px;
  font-weight: var(--fw-bold);
  color: var(--status-ready-color);
  letter-spacing: -0.3px;
}
.done-msg {
  font-size: 15px;
  color: rgba(255, 255, 255, 0.75);
}
.done-redirect {
  font-size: var(--fs-xs);
  color: rgba(255, 255, 255, 0.4);
  font-style: italic;
}

/* ── Spinner ── */
.pay-spinner {
  width: 36px;
  height: 36px;
  border: 3px solid rgba(255, 255, 255, 0.1);
  border-top-color: var(--brand-accent);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
