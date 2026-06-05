<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useShiftStore } from '../stores/shift'

const router = useRouter()
const auth   = useAuthStore()
const shift  = useShiftStore()

const step      = ref('login')   // 'login' | 'abertura'
const phone     = ref('')
const password  = ref('')
const loading   = ref(false)
const error     = ref('')
const abertura  = ref('')
const aberturaError = ref('')

function applyPhoneMask(e) {
  let v = e.target.value.replace(/\D/g, '').slice(0, 11)
  if (v.length > 6)      v = `(${v.slice(0,2)}) ${v.slice(2,7)}-${v.slice(7)}`
  else if (v.length > 2) v = `(${v.slice(0,2)}) ${v.slice(2)}`
  else if (v.length > 0) v = `(${v}`
  phone.value = v
}

async function handleLogin() {
  if (!phone.value || !password.value) {
    error.value = 'Preencha telefone e senha.'
    return
  }
  loading.value = true
  error.value   = ''
  try {
    await auth.loginWithPayload({ phone: phone.value, password: password.value })
    // Já existe turno aberto deste operador? Vai direto para a fila.
    const current = await shift.fetch()
    if (current) {
      router.push('/fila')
      return
    }
    step.value = 'abertura'
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'Credenciais inválidas.'
  } finally {
    loading.value = false
  }
}

async function confirmAbertura() {
  const amount = parseFloat(String(abertura.value).replace(',', '.'))
  if (isNaN(amount) || amount < 0) {
    aberturaError.value = 'Informe um valor válido (zero ou maior).'
    return
  }
  loading.value = true
  aberturaError.value = ''
  try {
    await shift.open(amount)
    router.push('/fila')
  } catch (err) {
    aberturaError.value = err.response?.data?.message || 'Erro ao abrir caixa.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <!-- ── Login ── -->
  <div v-if="step === 'login'" class="fullscreen-center fullscreen-center--kds">
    <div class="logo-text">PagFood</div>
    <h2>Caixa</h2>
    <p>Faça login para acessar o painel</p>

    <div class="login-form">
      <div v-if="error" class="msg msg-error msg-error--dark">{{ error }}</div>

      <div class="form-group form-group--dark">
        <label>Telefone</label>
        <input
          :value="phone"
          type="tel"
          placeholder="(00) 99999-0000"
          inputmode="numeric"
          autocomplete="username"
          @input="applyPhoneMask"
          @keyup.enter="handleLogin"
        />
      </div>

      <div class="form-group form-group--dark">
        <label>Senha</label>
        <input
          v-model="password"
          type="password"
          placeholder="Sua senha"
          @keyup.enter="handleLogin"
        />
      </div>

      <button
        class="btn btn-primary btn-block"
        :disabled="loading"
        @click="handleLogin"
      >
        <span v-if="loading" class="btn-spinner"></span>
        {{ loading ? 'Entrando...' : 'Entrar' }}
      </button>
    </div>
  </div>

  <!-- ── Abertura de caixa ── -->
  <div v-else class="fullscreen-center fullscreen-center--kds">
    <div class="logo-text">PagFood</div>
    <h2>Abertura de Caixa</h2>
    <p>Informe o valor em espécie disponível no caixa ao iniciar o turno.</p>

    <div class="login-form">
      <div v-if="aberturaError" class="msg msg-error msg-error--dark">{{ aberturaError }}</div>

      <div class="form-group form-group--dark">
        <label>Valor inicial (R$)</label>
        <input
          v-model="abertura"
          type="text"
          inputmode="decimal"
          placeholder="0,00"
          class="abertura-input"
          @keyup.enter="confirmAbertura"
          autofocus
        />
      </div>

      <button
        class="btn btn-primary btn-block"
        :disabled="loading"
        @click="confirmAbertura"
      >
        <span v-if="loading" class="btn-spinner"></span>
        {{ loading ? 'Abrindo...' : 'Abrir Caixa' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.abertura-input {
  font-size: 1.5rem;
  font-weight: 700;
  text-align: center;
  letter-spacing: 1px;
}
.btn-ghost {
  background: transparent;
  border: 1px solid rgba(255,255,255,0.2);
  color: rgba(255,255,255,0.55);
  font-size: var(--fs-sm);
}
.btn-ghost:hover { background: rgba(255,255,255,0.06); }
.abertura-skip { margin-top: 8px; }
</style>
