import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api'

export const useAuthStore = defineStore('auth', () => {
  const user       = ref(JSON.parse(localStorage.getItem('caixa_user')       || 'null'))
  const restaurant = ref(JSON.parse(localStorage.getItem('caixa_restaurant') || 'null'))

  const isAuthenticated = computed(() => !!user.value)

  async function loginWithPayload(payload) {
    const { data } = await api.post('/auth/login', payload)

    if (data.user.role !== 'cashier' && data.user.role !== 'manager') {
      throw new Error('Acesso restrito ao operador de caixa.')
    }

    localStorage.setItem('caixa_token', data.token)
    localStorage.setItem('caixa_user', JSON.stringify(data.user))
    user.value = data.user

    if (data.restaurant) {
      localStorage.setItem('caixa_restaurant', JSON.stringify(data.restaurant))
      restaurant.value = data.restaurant
    }

    return data
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } catch (e) { /* ignore */ }
    localStorage.removeItem('caixa_token')
    localStorage.removeItem('caixa_user')
    localStorage.removeItem('caixa_restaurant')
    user.value = null
    restaurant.value = null
  }

  return { user, restaurant, isAuthenticated, loginWithPayload, logout }
})
