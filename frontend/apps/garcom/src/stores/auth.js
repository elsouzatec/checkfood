import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api'

export const useAuthStore = defineStore('auth', () => {
  const user       = ref(JSON.parse(localStorage.getItem('garcom_user')       || 'null'))
  const restaurant = ref(JSON.parse(localStorage.getItem('garcom_restaurant') || 'null'))

  // IMPORTANTE: depende de user.value (reativo).
  // Antes usava apenas localStorage.getItem, que não é reactive — o computed
  // ficava cacheado em `false` após a inicialização e nunca reavaliava quando
  // o login acontecia, quebrando a renderização do header até o próximo F5.
  const isAuthenticated = computed(() => !!user.value)

  // Login legado (phone + password) — mantido para compatibilidade
  async function login(phone, password) {
    return loginWithPayload({ phone, password })
  }

  // Login genérico: aceita { phone, password } ou { name, password }
  async function loginWithPayload(payload) {
    const { data } = await api.post('/auth/login', payload)

    if (data.user.role !== 'waiter' && data.user.role !== 'manager') {
      throw new Error('Acesso restrito a garçons e gerentes.')
    }

    localStorage.setItem('garcom_token', data.token)
    localStorage.setItem('garcom_user', JSON.stringify(data.user))
    user.value = data.user

    if (data.restaurant) {
      localStorage.setItem('garcom_restaurant', JSON.stringify(data.restaurant))
      restaurant.value = data.restaurant
    }

    return data
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } catch (e) { /* ignore */ }
    localStorage.removeItem('garcom_token')
    localStorage.removeItem('garcom_user')
    localStorage.removeItem('garcom_restaurant')
    user.value = null
    restaurant.value = null
  }

  return { user, restaurant, isAuthenticated, login, loginWithPayload, logout }
})
