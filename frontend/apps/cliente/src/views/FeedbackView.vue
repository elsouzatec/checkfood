<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router = useRouter()

const sessionId   = ref(null)
const rating      = ref(0)
const hoverRating = ref(0)
const comment     = ref('')
const sending     = ref(false)
const sent        = ref(false)
const error       = ref('')
const alreadySent = ref(false)
const noSession   = ref(false)

const ratingLabels = ['', 'Péssimo', 'Ruim', 'Regular', 'Bom', 'Excelente']

onMounted(async () => {
  // Buscar session_id de múltiplas fontes
  let sid = localStorage.getItem('pf_feedback_session')

  if (!sid) {
    try {
      const stored = localStorage.getItem('pf_session')
      if (stored) {
        const parsed = JSON.parse(stored)
        sid = parsed.id || parsed
      }
    } catch (e) {
      sid = localStorage.getItem('pf_session')
    }
  }

  if (!sid) {
    noSession.value = true
    return
  }

  sessionId.value = sid

  // Verificar se já avaliou (token ainda está disponível neste ponto)
  try {
    const res = await api.get('/feedbacks/check/' + sid)
    if (res.data.has_feedback) {
      alreadySent.value = true
    }
  } catch (e) {
    // Token pode ter expirado — permitir tentativa de envio mesmo assim
  }
})

async function submit() {
  if (rating.value === 0) {
    error.value = 'Selecione uma nota.'
    return
  }
  sending.value = true
  error.value = ''
  try {
    await api.post('/feedbacks', {
      session_id: sessionId.value,
      rating: rating.value,
      comment: comment.value || null,
    })
    sent.value = true
  } catch (err) {
    if (err.response && err.response.status === 401) {
      error.value = 'Sessão expirada. Não foi possível enviar a avaliação.'
    } else {
      error.value = err.response?.data?.message || 'Erro ao enviar avaliação.'
    }
  } finally {
    sending.value = false
  }
}

function finish() {
  // Limpar TUDO do localStorage
  localStorage.removeItem('pf_token')
  localStorage.removeItem('pf_user')
  localStorage.removeItem('pf_session')
  localStorage.removeItem('pf_table')
  localStorage.removeItem('pf_orders')
  localStorage.removeItem('pf_feedback_session')
  window.location.href = '/'
}
</script>

<template>
  <div class="feedback-page">
    <div v-if="noSession" class="fb-card">
      <div class="fb-icon">🍽</div>
      <h2 class="fb-title">Nenhuma visita encontrada</h2>
      <p class="fb-subtitle">Escaneie o QR Code da mesa para começar.</p>
      <button class="fb-btn" @click="finish">Ir para o início</button>
    </div>

    <div v-else-if="alreadySent" class="fb-card">
      <div class="fb-icon">✅</div>
      <h2 class="fb-title">Avaliação já enviada</h2>
      <p class="fb-subtitle">Obrigado pelo seu feedback!</p>
      <button class="fb-btn" @click="finish">Fechar</button>
    </div>

    <div v-else-if="sent" class="fb-card">
      <div class="fb-icon">🎉</div>
      <h2 class="fb-title">Obrigado!</h2>
      <p class="fb-subtitle">Sua avaliação foi enviada com sucesso.</p>
      <button class="fb-btn" @click="finish">Fechar</button>
    </div>

    <div v-else class="fb-card">
      <div class="fb-icon">💬</div>
      <h2 class="fb-title">Como foi sua experiência?</h2>
      <p class="fb-subtitle">Sua opinião nos ajuda a melhorar!</p>

      <div v-if="error" class="fb-error">{{ error }}</div>

      <div class="stars-row">
        <button
          v-for="s in 5"
          :key="s"
          class="star-btn"
          :class="{ 'star-filled': s <= (hoverRating || rating) }"
          @mouseenter="hoverRating = s"
          @mouseleave="hoverRating = 0"
          @click="rating = s"
        >★</button>
      </div>
      <div class="stars-label">
        {{ rating === 0 ? 'Toque para avaliar' : ratingLabels[rating] }}
      </div>

      <div class="fb-field">
        <textarea
          v-model="comment"
          placeholder="Conte mais sobre sua experiência... (opcional)"
          rows="3"
        ></textarea>
      </div>

      <button
        class="fb-btn"
        :disabled="sending || rating === 0"
        @click="submit"
      >
        {{ sending ? 'Enviando...' : 'Enviar Avaliação' }}
      </button>

      <button class="fb-skip" @click="finish">Pular</button>
    </div>
  </div>
</template>

<style scoped>
/* Tudo abaixo consome tokens do design system — responde à paleta
   unificada (verde folha + laranja torrado + cream). */

.feedback-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--page-bg);
  padding: 20px;
}

.fb-card {
  background: var(--surface);
  border-radius: var(--radius-lg);
  padding: 36px 28px;
  width: 100%;
  max-width: 400px;
  text-align: center;
  box-shadow: var(--shadow-lg);
  border: 1px solid var(--border-subtle);
}

.fb-icon {
  font-size: 48px;
  margin-bottom: 12px;
  line-height: 1;
}

.fb-title {
  font-size: 22px;
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin-bottom: 6px;
  letter-spacing: -0.3px;
}

.fb-subtitle {
  font-size: var(--fs-md);
  color: var(--text-muted);
  margin-bottom: 24px;
  line-height: 1.4;
}

.fb-error {
  background: var(--status-pending-bg);
  border: 1px solid var(--status-pending-border);
  color: var(--status-danger-color);
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  margin-bottom: 16px;
}

/* ── Estrelas ── */
.stars-row {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-bottom: 8px;
}
.star-btn {
  font-size: 36px;
  background: none;
  border: none;
  cursor: pointer;
  color: var(--border-strong);
  transition: color var(--dur-fast), transform var(--dur-fast);
  padding: 4px;
  line-height: 1;
  touch-action: manipulation;
}
.star-btn:active { transform: scale(1.2); }
.star-filled {
  /* Âmbar estável entre temas — mesmo amarelo das estrelas do admin */
  color: var(--status-received-color);
}

.stars-label {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  margin-bottom: 20px;
  min-height: 20px;
  font-weight: var(--fw-semibold);
}

/* ── Textarea ── */
.fb-field { margin-bottom: 20px; }
.fb-field textarea {
  width: 100%;
  padding: 12px 16px;
  border: 1.5px solid var(--border-default);
  border-radius: var(--radius-sm);
  font-size: var(--fs-md);
  font-family: inherit;
  resize: none;
  color: var(--text-primary);
  background: var(--surface);
  outline: none;
  transition: border-color var(--dur-fast);
}
.fb-field textarea:focus { border-color: var(--brand-accent); }
.fb-field textarea::placeholder { color: var(--text-muted); }

/* ── Botão principal ── */
.fb-btn {
  width: 100%;
  padding: 14px;
  background: var(--brand-accent);
  color: var(--brand-accent-text);
  border: none;
  border-radius: var(--radius-sm);
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  cursor: pointer;
  font-family: inherit;
  touch-action: manipulation;
  transition: background var(--dur-normal), transform var(--dur-fast);
}
.fb-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.fb-btn:active:not(:disabled) {
  background: var(--brand-accent-hover);
  transform: scale(0.98);
}

/* ── Pular ── */
.fb-skip {
  background: none;
  border: none;
  color: var(--text-muted);
  font-size: var(--fs-sm);
  cursor: pointer;
  margin-top: 14px;
  padding: 8px;
  font-family: inherit;
  touch-action: manipulation;
  transition: color var(--dur-fast);
}
.fb-skip:hover { color: var(--text-secondary); }
</style>
