<script setup>
import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useAccountStore } from '../stores/account'
import { maskPhone, isValidPhone } from '../phone'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const account = useAccountStore()

const tableToken = route.params.token

// account → logado: entra na mesa com um toque
// phone   → entrada pelo telefone (com opção de criar conta)
// login   → telefone já tem conta: pede a senha
const mode = ref(account.isLoggedIn ? 'account' : 'phone')

const phone = ref('')
const name  = ref('')
const password = ref('')
const showPassword = ref(false)
const createAccount = ref(false)
const acceptTerms = ref(false)
const loading = ref(false)
const error = ref('')
const notice = ref('')

const submitLabel = computed(() => {
  if (loading.value) return 'Entrando...'
  if (mode.value === 'login') return 'Entrar na mesa'
  return createAccount.value ? 'Criar conta e pedir' : 'Entrar e Pedir'
})

function applyPhoneMask(e) {
  phone.value = maskPhone(e.target.value)
}

function tableError(err) {
  const status = err.response?.status
  if (status === 404) return 'Mesa não encontrada.'
  if (status === 409) return err.response.data?.message || 'Mesa ocupada por outro cliente.'
  return err.response?.data?.message || 'Erro ao conectar. Tente novamente.'
}

async function enterWithAccount() {
  loading.value = true
  error.value = ''
  try {
    await account.linkTable(tableToken)
    router.push('/cardapio')
  } catch (err) {
    if (err.response?.status === 409 && err.response.data?.other_restaurant) {
      // Conta é de outro restaurante: aqui o cliente entra pelo telefone.
      mode.value = 'phone'
      notice.value = 'Sua conta é de outro restaurante. Aqui, entre com seu telefone.'
    } else {
      error.value = tableError(err)
    }
  } finally {
    loading.value = false
  }
}

async function handleSubmit() {
  if (!isValidPhone(phone.value)) {
    error.value = 'Informe um telefone válido.'
    return
  }
  if (mode.value === 'login' && !password.value) {
    error.value = 'Digite sua senha.'
    return
  }
  if (mode.value === 'phone' && createAccount.value) {
    if (name.value.trim().length < 2) { error.value = 'Informe seu nome para criar a conta.'; return }
    if (password.value.length < 8)    { error.value = 'A senha precisa de pelo menos 8 caracteres.'; return }
    if (!acceptTerms.value)           { error.value = 'Aceite os termos para criar a conta.'; return }
  }

  loading.value = true
  error.value = ''
  try {
    if (mode.value === 'login') {
      await account.login({ phone: phone.value, password: password.value, table_token: tableToken })
      await account.linkTable(tableToken)
    } else if (createAccount.value) {
      await account.register({
        table_token: tableToken,
        name: name.value,
        phone: phone.value,
        password: password.value,
        accept_terms: true,
      })
      await account.linkTable(tableToken)
    } else {
      await auth.createSession(tableToken, phone.value, name.value)
    }
    router.push('/cardapio')
  } catch (err) {
    const data = err.response?.data
    if (err.response?.status === 409 && data?.requires_login) {
      switchToLogin('Este telefone tem conta. Digite sua senha para entrar.')
    } else if (err.response?.status === 422 && data?.account_exists) {
      switchToLogin('Você já tem conta aqui. Digite sua senha para entrar.')
    } else if (mode.value === 'login' && err.response?.status === 401) {
      error.value = 'Senha incorreta.'
    } else {
      error.value = tableError(err)
    }
  } finally {
    loading.value = false
  }
}

function switchToLogin(message) {
  mode.value = 'login'
  createAccount.value = false
  password.value = ''
  notice.value = message
}

async function notMe() {
  await account.logout()
  mode.value = 'phone'
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

    <!-- Logado: um toque para entrar -->
    <div v-if="mode === 'account'" class="entry-card">
      <div class="entry-card-handle" aria-hidden="true"></div>

      <h2 class="entry-card-title">Olá, {{ account.firstName }}!</h2>
      <p class="entry-card-sub">
        Entre na mesa com sua conta{{ account.restaurant ? ' do ' + account.restaurant.name : '' }}.
        Seus pedidos ficam salvos no seu histórico.
      </p>

      <div v-if="error" class="msg msg-error">{{ error }}</div>

      <button class="entry-submit-btn" :disabled="loading" @click="enterWithAccount">
        <span v-if="loading" class="btn-spinner"></span>
        {{ loading ? 'Entrando...' : 'Entrar na mesa' }}
      </button>
      <button class="entry-link" @click="notMe">Não é você? <strong>Sair</strong></button>
    </div>

    <!-- Telefone / senha -->
    <div v-else class="entry-card">
      <div class="entry-card-handle" aria-hidden="true"></div>

      <h2 class="entry-card-title">{{ mode === 'login' ? 'Entre com sua conta' : 'Vamos começar!' }}</h2>
      <p class="entry-card-sub">
        {{ mode === 'login' ? 'Use o telefone e a senha da sua conta' : 'Informe seu telefone para fazer pedidos' }}
      </p>

      <div v-if="notice" class="msg msg-warning">{{ notice }}</div>
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

      <div v-if="mode === 'phone'" class="form-group">
        <label>Seu nome <span v-if="!createAccount" class="label-hint">(opcional)</span></label>
        <input
          v-model="name"
          type="text"
          placeholder="Como quer ser chamado?"
          autocomplete="given-name"
          @keyup.enter="handleSubmit"
        />
      </div>

      <label v-if="mode === 'phone'" class="terms-check">
        <input v-model="createAccount" type="checkbox" />
        <span>Criar minha conta para ver meus pedidos depois</span>
      </label>

      <div v-if="mode === 'login' || createAccount" class="form-group">
        <label>{{ mode === 'login' ? 'Senha' : 'Crie uma senha' }}</label>
        <div class="password-field">
          <input
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            :placeholder="mode === 'login' ? 'Sua senha' : 'Mínimo de 8 caracteres'"
            :autocomplete="mode === 'login' ? 'current-password' : 'new-password'"
            @keyup.enter="handleSubmit"
          />
          <button type="button" class="password-toggle" @click="showPassword = !showPassword">
            {{ showPassword ? 'Ocultar' : 'Mostrar' }}
          </button>
        </div>
      </div>

      <label v-if="mode === 'phone' && createAccount" class="terms-check">
        <input v-model="acceptTerms" type="checkbox" />
        <span>Li e aceito os <router-link to="/termos" target="_blank">termos de uso e a política de privacidade</router-link>.</span>
      </label>

      <button class="entry-submit-btn" :disabled="loading" @click="handleSubmit">
        <span v-if="loading" class="btn-spinner"></span>
        {{ submitLabel }}
      </button>

      <template v-if="mode === 'login'">
        <p class="entry-note">Esqueceu a senha? Peça ao atendente para redefinir seu acesso.</p>
        <button class="entry-link" @click="mode = 'phone'; notice = ''">Usar outro telefone</button>
      </template>
    </div>

  </div>
</template>

<style scoped>
/* Decoração flutuante da zona de identidade */
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
</style>
