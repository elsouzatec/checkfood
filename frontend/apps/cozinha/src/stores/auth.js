import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api'

export const useAuthStore = defineStore('auth', () => {
  const user       = ref(JSON.parse(localStorage.getItem('kitchen_user')       || 'null'))
  const restaurant = ref(JSON.parse(localStorage.getItem('kitchen_restaurant') || 'null'))

  // IMPORTANTE: depende de user.value (reativo).
  // Antes usava apenas localStorage.getItem('auth_token'), que não é reactive —
  // o computed ficava cacheado em `false` após a inicialização e nunca
  // reavaliava quando o login acontecia. Isso quebrava a renderização do
  // header até o próximo F5.
  const isAuthenticated = computed(() => !!user.value)

  async function login(phone, password) {
    return loginWithPayload({ phone, password })
  }

  async function loginWithPayload(payload) {
    const { data } = await api.post('/auth/login', payload)

    if (data.user.role !== 'kitchen' && data.user.role !== 'manager') {
      throw new Error('Acesso restrito à equipe de cozinha.')
    }

    localStorage.setItem('auth_token', data.token)
    localStorage.setItem('kitchen_user', JSON.stringify(data.user))
    user.value = data.user

    if (data.restaurant) {
      localStorage.setItem('kitchen_restaurant', JSON.stringify(data.restaurant))
      restaurant.value = data.restaurant
    }

    return data
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } catch (e) { /* ignore */ }
    localStorage.removeItem('auth_token')
    localStorage.removeItem('kitchen_user')
    localStorage.removeItem('kitchen_restaurant')
    user.value = null
    restaurant.value = null
  }

  return { user, restaurant, isAuthenticated, login, loginWithPayload, logout }
})
