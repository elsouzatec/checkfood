<script setup>
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAccountStore } from '../stores/account'
import { maskPhone, isValidPhone } from '../phone'
import { logoSrc } from '../links'

// Login da conta: telefone + senha. Com ?r=slug fica restrito ao restaurante;
// sem ele, se a senha confere em mais de um, o cliente escolhe qual acessar.
var route = useRoute()
var router = useRouter()
var account = useAccountStore()

var slug = ref(route.query.r || '')
var phone = ref('')
var password = ref('')
var showPassword = ref(false)
var loading = ref(false)
var error = ref('')
var choices = ref([])

async function submit(chosenSlug) {
  if (!isValidPhone(phone.value)) { error.value = 'Informe um telefone válido.'; return }
  if (!password.value)            { error.value = 'Digite sua senha.'; return }

  loading.value = true
  error.value = ''
  try {
    var result = await account.login({
      phone: phone.value,
      password: password.value,
      restaurant_slug: chosenSlug || slug.value || undefined,
    })
    if (!result.ok) {
      choices.value = result.restaurants
      return
    }
    router.replace('/minha-conta')
  } catch (err) {
    var status = err.response?.status
    if (status === 401)      error.value = 'Telefone ou senha inválidos.'
    else if (status === 404) error.value = 'Restaurante não encontrado.'
    else if (status === 429) error.value = 'Muitas tentativas. Aguarde um minuto.'
    else                     error.value = 'Erro ao conectar. Tente novamente.'
  } finally {
    loading.value = false
  }
}

function goRegister() {
  router.push(slug.value ? { path: '/cadastro', query: { r: slug.value } } : '/cadastro')
}
</script>

<template>
  <div class="entry-screen">
    <div class="entry-brand">
      <div class="entry-logo">PagFood</div>
      <p class="entry-tagline">Acesse sua conta</p>
    </div>

    <!-- Conta em mais de um restaurante: escolher -->
    <div v-if="choices.length" class="entry-card">
      <div class="entry-card-handle" aria-hidden="true"></div>
      <h2 class="entry-card-title">Qual restaurante?</h2>
      <p class="entry-card-sub">Você tem conta em mais de um. Cada um tem a sua.</p>

      <div v-if="error" class="msg msg-error">{{ error }}</div>

      <button
        v-for="r in choices"
        :key="r.slug"
        class="acc-action"
        :disabled="loading"
        @click="submit(r.slug)"
      >
        <img v-if="r.logo_url" :src="logoSrc(r.logo_url)" :alt="r.name" class="acc-action-icon" />
        <span v-else class="acc-action-icon">🍽️</span>
        <span class="acc-action-body"><span class="acc-action-title">{{ r.name }}</span></span>
        <span class="acc-action-arrow">→</span>
      </button>

      <button class="entry-link" @click="choices = []">Voltar</button>
    </div>

    <div v-else class="entry-card">
      <div class="entry-card-handle" aria-hidden="true"></div>
      <h2 class="entry-card-title">Entrar</h2>
      <p class="entry-card-sub">Use o telefone e a senha que você cadastrou no restaurante.</p>

      <div v-if="error" class="msg msg-error">{{ error }}</div>

      <div class="form-group">
        <label>Telefone</label>
        <input
          :value="phone"
          type="tel"
          placeholder="(00) 99999-9999"
          inputmode="numeric"
          autocomplete="tel"
          @input="phone = maskPhone($event.target.value)"
          @keyup.enter="submit()"
        />
      </div>

      <div class="form-group">
        <label>Senha</label>
        <div class="password-field">
          <input
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            placeholder="Sua senha"
            autocomplete="current-password"
            @keyup.enter="submit()"
          />
          <button type="button" class="password-toggle" @click="showPassword = !showPassword">
            {{ showPassword ? 'Ocultar' : 'Mostrar' }}
          </button>
        </div>
      </div>

      <button class="entry-submit-btn" :disabled="loading" @click="submit()">
        <span v-if="loading" class="btn-spinner"></span>
        {{ loading ? 'Entrando...' : 'Entrar' }}
      </button>

      <button class="entry-link" @click="goRegister">Não tem conta? <strong>Criar conta</strong></button>
      <p class="entry-note">Esqueceu a senha? Peça ao restaurante para redefinir seu acesso e crie a conta de novo com o mesmo telefone.</p>
      <button class="entry-link" @click="router.push('/')">← Voltar ao início</button>
    </div>
  </div>
</template>
