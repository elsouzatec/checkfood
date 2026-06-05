import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(JSON.parse(localStorage.getItem('platform_user') || 'null'))
  const isAuthenticated = computed(() => !!user.value && !!localStorage.getItem('platform_token'))

  async function login(email, password) {
    const { data } = await api.post('/auth/login', { email, password })

    if (data.user.role !== 'super_admin') {
      throw new Error('Acesso restrito ao administrador da plataforma.')
    }

    localStorage.setItem('platform_token', data.token)
    localStorage.setItem('platform_user', JSON.stringify(data.user))
    user.value = data.user
    return data
  }

  async function logout() {
    try { await api.post('/auth/logout') } catch (e) { /* ignore */ }
    localStorage.removeItem('platform_token')
    localStorage.removeItem('platform_user')
    user.value = null
  }

  return { user, isAuthenticated, login, logout }
})
