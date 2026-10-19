<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useAccountStore } from '../stores/account'
import { maskPhone } from '../phone'

// /conta — dentro da mesa. Cliente que entrou só com o telefone cria a conta
// aqui sem sair da visita: os pedidos desta visita já entram no histórico.
var router = useRouter()
var auth = useAuthStore()
var account = useAccountStore()

var name = ref(auth.user?.name && auth.user.name !== 'Cliente' ? auth.user.name : '')
var password = ref('')
var showPassword = ref(false)
var acceptTerms = ref(false)
var loading = ref(false)
var error = ref('')
var created = ref(false)

// Já tem conta (entrou pela conta, ou criou em outro aparelho).
var hasAccount = computed(function() {
  return account.isLoggedIn || !!auth.user?.registered_at
})

async function submit() {
  if (name.value.trim().length < 2) { error.value = 'Informe seu nome.'; return }
  if (password.value.length < 8)    { error.value = 'A senha precisa de pelo menos 8 caracteres.'; return }
  if (!acceptTerms.value)           { error.value = 'Aceite os termos para criar a conta.'; return }

  loading.value = true
  error.value = ''
  try {
    await account.upgradeFromTable({ name: name.value.trim(), password: password.value, accept_terms: true })
    created.value = true
  } catch (err) {
    error.value = err.response?.data?.message || 'Não foi possível criar a conta.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="card account-card">
      <template v-if="created">
        <div class="account-icon">🎉</div>
        <h2 class="account-title">Conta criada!</h2>
        <p class="account-desc">
          Os pedidos desta visita já estão no seu histórico. Na próxima vez, é só entrar
          com seu telefone e senha.
        </p>
        <button class="btn btn-primary" @click="router.push('/cardapio')">Voltar ao cardápio</button>
      </template>

      <template v-else-if="hasAccount">
        <div class="account-icon">👤</div>
        <h2 class="account-title">Sua conta</h2>
        <p class="account-desc">Veja o histórico dos seus pedidos e seus dados.</p>
        <button v-if="account.isLoggedIn" class="btn btn-primary" @click="router.push('/minha-conta')">Abrir minha conta</button>
        <button v-else class="btn btn-primary" @click="router.push({ path: '/entrar', query: { r: auth.restaurant?.slug } })">Entrar na conta</button>
      </template>

      <template v-else>
        <div class="account-icon">👤</div>
        <h2 class="account-title">Crie sua conta</h2>
        <p class="account-desc">
          Guarde o histórico dos seus pedidos e entre na mesa com um toque nas próximas visitas.
        </p>

        <div v-if="error" class="msg msg-error">{{ error }}</div>

        <div class="form-group">
          <label>Nome</label>
          <input v-model="name" type="text" placeholder="Seu nome" autocomplete="name" />
        </div>
        <div class="form-group">
          <label>Crie uma senha</label>
          <div class="password-field">
            <input
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="Mínimo de 8 caracteres"
              autocomplete="new-password"
            />
            <button type="button" class="password-toggle" @click="showPassword = !showPassword">
              {{ showPassword ? 'Ocultar' : 'Mostrar' }}
            </button>
          </div>
        </div>
        <label class="terms-check">
          <input v-model="acceptTerms" type="checkbox" />
          <span>Li e aceito os <router-link to="/termos" target="_blank">termos de uso e a política de privacidade</router-link>.</span>
        </label>

        <button class="btn btn-primary" :disabled="loading" @click="submit">
          {{ loading ? 'Criando...' : 'Criar conta' }}
        </button>
        <p class="entry-note">Seu telefone ({{ maskPhone(auth.user?.phone) }}) será o login da conta.</p>
      </template>
    </div>
  </div>
</template>

<style scoped>
.account-card {
  padding: 28px 20px;
  text-align: center;
}
.account-card .form-group,
.account-card .terms-check { text-align: left; }
.account-icon { font-size: 44px; margin-bottom: 8px; }
.account-title {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin-bottom: 6px;
}
.account-desc {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  line-height: 1.5;
  margin-bottom: 20px;
}
</style>
