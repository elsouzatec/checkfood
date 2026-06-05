import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api'

export const useAuthStore = defineStore('auth', () => {
  const user       = ref(JSON.parse(localStorage.getItem('admin_user')       || 'null'))
  const restaurant = ref(JSON.parse(localStorage.getItem('admin_restaurant') || 'null'))
  const isAuthenticated = computed(() => !!user.value && !!localStorage.getItem('admin_token'))

  async function login(phone, password) {
    return loginWithPayload({ phone, password })
  }

  async function loginWithPayload(payload) {
    const { data } = await api.post('/auth/login', payload)

    if (data.user.role !== 'manager') {
      throw new Error('Acesso restrito a gerentes.')
    }

    localStorage.setItem('admin_token', data.token)
    localStorage.setItem('admin_user', JSON.stringify(data.user))
    user.value = data.user

    if (data.restaurant) {
      localStorage.setItem('admin_restaurant', JSON.stringify(data.restaurant))
      restaurant.value = data.restaurant
    }
    return data
  }

  async function logout() {
    try { await api.post('/auth/logout') } catch (e) { /* ignore */ }
    localStorage.removeItem('admin_token')
    localStorage.removeItem('admin_user')
    localStorage.removeItem('admin_restaurant')
    user.value = null
    restaurant.value = null
  }

  return { user, restaurant, isAuthenticated, login, loginWithPayload, logout }
})
