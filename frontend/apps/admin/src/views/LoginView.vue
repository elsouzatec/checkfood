<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth   = useAuthStore()

const phone    = ref('')
const password = ref('')
const loading  = ref(false)
const error    = ref('')

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
    router.push({ name: 'dashboard' })
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'Credenciais inválidas.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-screen">
    <div class="login-card">
      <div class="login-logo">PagFood</div>
      <div class="login-subtitle">Painel do Gerente</div>

      <div v-if="error" class="error-msg">{{ error }}</div>

      <div class="form-group">
        <label>Telefone</label>
        <input
          :value="phone"
          type="tel"
          placeholder="(91) 99999-0001"
          inputmode="numeric"
          autocomplete="username"
          @input="applyPhoneMask"
          @keyup.enter="handleLogin"
        />
      </div>

      <div class="form-group">
        <label>Senha</label>
        <input
          v-model="password"
          type="password"
          placeholder="Sua senha"
          autocomplete="current-password"
          @keyup.enter="handleLogin"
        />
      </div>

      <button
        class="btn btn-primary login-submit"
        :disabled="loading"
        @click="handleLogin"
      >
        {{ loading ? 'Entrando...' : 'Entrar' }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.login-submit {
  width: 100%;
  padding: 11px 16px;
  margin-top: 6px;
}
</style>
