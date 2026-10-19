<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'
import { useAccountStore } from '../stores/account'
import { maskPhone, isValidPhone } from '../phone'

// Cadastro da conta — sempre num restaurante (a conta é por restaurante).
// O restaurante vem de ?r=slug (link dele) ou do último visitado.
var route = useRoute()
var router = useRouter()
var account = useAccountStore()

var restaurant = ref(null)
var loadingRestaurant = ref(true)

var name = ref('')
var phone = ref('')
var password = ref('')
var showPassword = ref(false)
var acceptTerms = ref(false)
var loading = ref(false)
var error = ref('')

onMounted(async function() {
  var slug = route.query.r
  if (!slug) {
    try { slug = JSON.parse(localStorage.getItem('pf_last_restaurant') || 'null')?.slug } catch (e) {}
  }
  if (slug) {
    try {
      var res = await api.get('/restaurants/by-slug/' + encodeURIComponent(slug))
      restaurant.value = res.data.restaurant
    } catch (e) { /* sem restaurante válido → instrução abaixo */ }
  }
  loadingRestaurant.value = false
})

async function submit() {
  if (name.value.trim().length < 2) { error.value = 'Informe seu nome.'; return }
  if (!isValidPhone(phone.value))   { error.value = 'Informe um telefone válido com DDD.'; return }
  if (password.value.length < 8)    { error.value = 'A senha precisa de pelo menos 8 caracteres.'; return }
  if (!acceptTerms.value)           { error.value = 'Aceite os termos para criar a conta.'; return }

  loading.value = true
  error.value = ''
  try {
    await account.register({
      restaurant_slug: restaurant.value.slug,
      name: name.value,
      phone: phone.value,
      password: password.value,
      accept_terms: true,
    })
    router.replace('/minha-conta')
  } catch (err) {
    var data = err.response?.data
    if (data?.account_exists)                error.value = 'Este telefone já tem conta aqui. Entre com sua senha.'
    else if (err.response?.status === 429)   error.value = 'Muitas tentativas. Aguarde um pouco.'
    else if (err.response?.status === 422)   error.value = data?.message || 'Confira os dados informados.'
    else                                     error.value = 'Erro ao conectar. Tente novamente.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="entry-screen">
    <div class="entry-brand">
      <div class="entry-logo">PagFood</div>
      <p class="entry-tagline">{{ restaurant ? 'Conta no ' + restaurant.name : 'Criar conta' }}</p>
    </div>

    <div class="entry-card">
      <div class="entry-card-handle" aria-hidden="true"></div>

      <p v-if="loadingRestaurant" class="loading">Carregando...</p>

      <template v-else-if="!restaurant">
        <h2 class="entry-card-title">Em qual restaurante?</h2>
        <p class="entry-card-sub">
          A conta é criada em cada restaurante. Escaneie o QR Code da mesa ou abra o link
          que o restaurante divulga para se cadastrar.
        </p>
        <button class="entry-submit-btn" @click="router.push('/')">Escanear QR Code da mesa</button>
        <button class="entry-link" @click="router.push('/entrar')">Já tenho conta — <strong>entrar</strong></button>
      </template>

      <template v-else>
        <h2 class="entry-card-title">Criar conta</h2>
        <p class="entry-card-sub">Entre na mesa com um toque e acompanhe seus pedidos no {{ restaurant.name }}.</p>

        <div v-if="error" class="msg msg-error">{{ error }}</div>

        <div class="form-group">
          <label>Nome</label>
          <input v-model="name" type="text" placeholder="Seu nome" autocomplete="name" />
        </div>

        <div class="form-group">
          <label>Telefone</label>
          <input
            :value="phone"
            type="tel"
            placeholder="(00) 99999-9999"
            inputmode="numeric"
            autocomplete="tel"
            @input="phone = maskPhone($event.target.value)"
          />
        </div>

        <div class="form-group">
          <label>Senha</label>
          <div class="password-field">
            <input
              v-model="password"
              :type="showPassword ? 'text' : 'password'"
              placeholder="Mínimo de 8 caracteres"
              autocomplete="new-password"
              @keyup.enter="submit"
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

        <button class="entry-submit-btn" :disabled="loading" @click="submit">
          <span v-if="loading" class="btn-spinner"></span>
          {{ loading ? 'Criando...' : 'Criar conta' }}
        </button>

        <button class="entry-link" @click="router.push({ path: '/entrar', query: { r: restaurant.slug } })">
          Já tem conta? <strong>Entrar</strong>
        </button>
      </template>
    </div>
  </div>
</template>
