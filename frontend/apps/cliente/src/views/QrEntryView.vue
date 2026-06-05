<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const phone = ref('')
const name  = ref('')
const loading = ref(false)
const error = ref('')

function applyPhoneMask(e) {
  let v = e.target.value.replace(/\D/g, '').slice(0, 11)
  if (v.length > 6)      v = '(' + v.slice(0,2) + ') ' + v.slice(2,7) + '-' + v.slice(7)
  else if (v.length > 2) v = '(' + v.slice(0,2) + ') ' + v.slice(2)
  else if (v.length > 0) v = '(' + v
  phone.value = v
}

async function handleSubmit() {
  if (!phone.value || phone.value.replace(/\D/g, '').length < 10) {
    error.value = 'Informe um telefone válido.'
    return
  }
  loading.value = true
  error.value = ''
  try {
    await auth.createSession(route.params.token, phone.value, name.value)
    router.push('/cardapio')
  } catch (err) {
    if (err.response?.status === 409)      error.value = 'Mesa ocupada por outro cliente.'
    else if (err.response?.status === 404) error.value = 'Mesa não encontrada.'
    else                                   error.value = 'Erro ao conectar. Tente novamente.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="entry-screen">

    <!-- Zona de identidade -->
    <div class="entry-brand">
      <div class="entry-logo">PagFood</div>
      <p class="entry-tagline">Peça no conforto da sua mesa</p>
      <div class="entry-deco" aria-hidden="true">
        <span class="deco-icon deco-1">🍽️</span>
        <span class="deco-icon deco-2">✨</span>
        <span class="deco-icon deco-3">🍷</span>
      </div>
    </div>

    <!-- Cartão do formulário -->
    <div class="entry-card">
      <div class="entry-card-handle" aria-hidden="true"></div>

      <h2 class="entry-card-title">Vamos começar!</h2>
      <p class="entry-card-sub">Informe seu telefone para fazer pedidos</p>

      <div v-if="error" class="msg msg-error">{{ error }}</div>

      <div class="form-group">
        <label>Telefone</label>
        <input
          :value="phone"
          type="tel"
          placeholder="(00) 99999-9999"
          inputmode="numeric"
          autocomplete="tel"
          @input="applyPhoneMask"
          @keyup.enter="handleSubmit"
        />
      </div>

      <div class="form-group">
        <label>Seu nome <span class="label-hint">(opcional)</span></label>
        <input
          v-model="name"
          type="text"
          placeholder="Como quer ser chamado?"
          autocomplete="given-name"
          @keyup.enter="handleSubmit"
        />
      </div>

      <button class="entry-submit-btn" :disabled="loading" @click="handleSubmit">
        <span v-if="loading" class="btn-spinner"></span>
        {{ loading ? 'Entrando...' : 'Entrar e Pedir' }}
      </button>
    </div>

  </div>
</template>

<style scoped>
.entry-screen {
  min-height: 100vh;
  display: flex;
  flex-direction: column;
  background: var(--sidebar-bg);
  overflow: hidden;
}

/* ── Zona de identidade ── */
.entry-brand {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 48px 24px 32px;
  text-align: center;
  position: relative;
}

.entry-logo {
  font-size: 44px;
  font-weight: var(--fw-black);
  color: var(--brand-accent);
  letter-spacing: -1px;
  line-height: 1;
  margin-bottom: 12px;
}

.entry-tagline {
  font-size: var(--fs-md);
  color: rgba(255, 255, 255, 0.70);
  font-weight: var(--fw-medium);
}

/* Decoração flutuante */
.entry-deco {
  position: absolute;
  inset: 0;
  pointer-events: none;
  overflow: hidden;
}
.deco-icon {
  position: absolute;
  font-size: 28px;
  opacity: 0.10;
  animation: deco-float 6s var(--ease-inout) infinite;
}
.deco-1 { top: 18%;    left: 10%;   animation-delay: 0s;   animation-duration: 7s; }
.deco-2 { top: 28%;    right: 12%;  animation-delay: 2.5s; animation-duration: 5s; }
.deco-3 { bottom: 20%; left: 18%;   animation-delay: 1.2s; animation-duration: 8s; }

@keyframes deco-float {
  0%, 100% { transform: translateY(0) rotate(-4deg); }
  50%       { transform: translateY(-10px) rotate(4deg); }
}

/* ── Cartão do formulário ── */
.entry-card {
  background: var(--surface);
  border-radius: 24px 24px 0 0;
  padding: 12px 24px calc(32px + env(safe-area-inset-bottom, 0px));
  animation: card-slide-up var(--dur-slow) var(--ease-spring) both;
}

@keyframes card-slide-up {
  from { transform: translateY(40px); opacity: 0; }
  to   { transform: translateY(0);    opacity: 1; }
}

.entry-card-handle {
  width: 40px;
  height: 4px;
  background: var(--border-default);
  border-radius: 2px;
  margin: 0 auto 20px;
}

.entry-card-title {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin-bottom: 4px;
}

.entry-card-sub {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  margin-bottom: 20px;
  line-height: 1.4;
}

/* Botão de submit */
.entry-submit-btn {
  width: 100%;
  padding: 15px;
  background: var(--brand-accent);
  color: #FFFFFF;
  border: none;
  border-radius: var(--radius);
  font-size: var(--fs-lg);
  font-weight: var(--fw-bold);
  font-family: var(--font-base);
  cursor: pointer;
  touch-action: manipulation;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  box-shadow: 0 4px 16px rgba(221, 111, 45, 0.30);
  transition:
    background var(--dur-normal) var(--ease-out),
    transform var(--dur-instant) var(--ease-out);
  margin-top: 4px;
}
.entry-submit-btn:active:not(:disabled) {
  background: var(--brand-accent-hover);
  transform: scale(0.98);
}
.entry-submit-btn:disabled { opacity: 0.65; cursor: not-allowed; }
</style>
