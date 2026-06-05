<script setup>
/**
 * RetornoView — /garcom/retorno
 *
 * Página de callback após pagamento no terminal POS.
 * O app da adquirente redireciona para:
 *   https://pagfood.online/garcom/retorno
 *     ?status=approved|rejected|pending
 *     &payment_id={terminal_payment_id}
 *     &external_reference={nosso_payment_id}
 *
 * Esta view:
 *  1. Lê os query params
 *  2. Chama POST /api/payments/{id}/confirm-pos
 *  3. Redireciona para /mesas (sucesso) ou volta ao modal (recusa)
 */
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router  = useRouter()
const status  = ref('loading')
const message = ref('')

onMounted(async () => {
  const params = new URLSearchParams(window.location.search)

  const callbackStatus    = params.get('status')
  const terminalPaymentId = params.get('payment_id')
  const externalReference = params.get('external_reference')

  if (!externalReference) {
    message.value = 'Referência de pagamento não encontrada.'
    status.value  = 'error'
    return
  }

  try {
    await api.post(`/payments/${externalReference}/confirm-pos`, {
      status:               callbackStatus,
      terminal_payment_id:  terminalPaymentId,
      external_reference:   externalReference,
    })

    if (callbackStatus === 'approved') {
      status.value  = 'approved'
      message.value = 'Pagamento confirmado!'
      setTimeout(() => router.replace('/mesas'), 2500)
    } else {
      status.value  = 'rejected'
      message.value = 'Pagamento recusado. Escolha outro método.'
      setTimeout(() => router.replace('/mesas'), 3000)
    }

  } catch (err) {
    const msg = err.response?.data?.message

    if (err.response?.status === 422 && msg?.includes('recusado')) {
      status.value  = 'rejected'
      message.value = 'Pagamento recusado pelo terminal.'
    } else {
      status.value  = 'error'
      message.value = msg || 'Erro ao confirmar pagamento.'
    }

    setTimeout(() => router.replace('/mesas'), 3000)
  }
})
</script>

<template>
  <div class="retorno-screen">
    <!-- Loading -->
    <div v-if="status === 'loading'" class="retorno-content">
      <div class="retorno-spinner"></div>
      <p>Confirmando pagamento...</p>
    </div>

    <!-- Aprovado -->
    <div v-else-if="status === 'approved'" class="retorno-content">
      <div class="retorno-icon">✅</div>
      <div class="retorno-title retorno-approved">Pagamento Confirmado!</div>
      <p class="retorno-msg">Mesa liberada. Redirecionando...</p>
    </div>

    <!-- Recusado -->
    <div v-else-if="status === 'rejected'" class="retorno-content">
      <div class="retorno-icon">❌</div>
      <div class="retorno-title retorno-rejected">Pagamento Recusado</div>
      <p class="retorno-msg">{{ message }}</p>
      <p class="retorno-hint">Redirecionando para as mesas...</p>
    </div>

    <!-- Erro -->
    <div v-else-if="status === 'error'" class="retorno-content">
      <div class="retorno-icon">⚠️</div>
      <div class="retorno-title">Erro</div>
      <p class="retorno-msg">{{ message }}</p>
      <button class="retorno-back-btn" @click="$router.replace('/mesas')">
        Voltar para Mesas
      </button>
    </div>
  </div>
</template>

<style scoped>
.retorno-screen {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--sidebar-bg);
  color: #FFFFFF;
}
.retorno-content {
  text-align: center;
  padding: 32px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  color: #FFFFFF;
}
.retorno-icon { font-size: 64px; line-height: 1; }
.retorno-title {
  font-size: var(--fs-2xl);
  font-weight: var(--fw-bold);
  color: #FFFFFF;
}
.retorno-approved { color: #8BBD68; }
.retorno-rejected { color: #FFB3B9; }

.retorno-msg {
  font-size: 15px;
  color: rgba(255, 255, 255, 0.75);
}
.retorno-hint {
  font-size: var(--fs-xs);
  color: rgba(255, 255, 255, 0.4);
}

.retorno-spinner {
  width: 40px;
  height: 40px;
  border: 3px solid rgba(255, 255, 255, 0.15);
  border-top-color: var(--brand-accent);
  border-radius: 50%;
  animation: retorno-spin 0.8s linear infinite;
}
@keyframes retorno-spin { to { transform: rotate(360deg); } }

.retorno-back-btn {
  margin-top: 8px;
  padding: 12px 24px;
  background: var(--brand-accent);
  border: none;
  border-radius: var(--radius-sm);
  color: #FFFFFF;
  font-size: var(--fs-md);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  font-family: inherit;
  transition: background var(--dur-normal);
}
.retorno-back-btn:hover { background: var(--brand-accent-hover); }
</style>
