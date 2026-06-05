<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const password = ref('')
const loading = ref(false)
const error = ref('')

async function handleLogin() {
  if (!email.value || !password.value) {
    error.value = 'Informe e-mail e senha.'
    return
  }
  loading.value = true
  error.value = ''
  try {
    await auth.login(email.value.trim(), password.value)
    router.push({ name: 'dashboard' })
  } catch (err) {
    error.value = err.response?.data?.message || err.message || 'Falha no login.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-screen">
    <div class="login-card">
      <div class="login-logo">PagFood</div>
      <div class="login-subtitle">Administração da Plataforma</div>

      <div v-if="error" class="alert error">{{ error }}</div>

      <form @submit.prevent="handleLogin">
        <div class="field">
          <label>E-mail</label>
          <input v-model="email" type="email" autocomplete="username" placeholder="super@exemplo.com" />
        </div>
        <div class="field">
          <label>Senha</label>
          <input v-model="password" type="password" autocomplete="current-password" placeholder="••••••••" />
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%; margin-top:10px" :disabled="loading">
          {{ loading ? 'Entrando…' : 'Entrar' }}
        </button>
      </form>
    </div>
  </div>
</template>
