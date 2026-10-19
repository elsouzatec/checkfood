import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api'
import accountApi from '../accountApi'
import { useAuthStore } from './auth'

function readJson(key) {
  try { return JSON.parse(localStorage.getItem(key) || 'null') } catch (e) { return null }
}

// Conta do cliente — POR RESTAURANTE. Vive ao lado da sessão da mesa (auth
// store): a conta persiste entre visitas; a mesa é aberta a cada visita via
// linkTable() e encerrada no pagamento/feedback como sempre.
export const useAccountStore = defineStore('account', () => {
  var user = ref(readJson('pf_account_user'))
  var restaurant = ref(readJson('pf_account_restaurant'))
  var token = ref(localStorage.getItem('pf_account_token'))

  var isLoggedIn = computed(function() {
    return !!token.value && !!user.value
  })

  var firstName = computed(function() {
    return (user.value?.name || '').split(' ')[0] || ''
  })

  function setAccount(data) {
    localStorage.setItem('pf_account_token', data.token)
    localStorage.setItem('pf_account_user', JSON.stringify(data.user))
    localStorage.setItem('pf_account_restaurant', JSON.stringify(data.restaurant))
    localStorage.setItem('pf_last_restaurant', JSON.stringify(data.restaurant))
    token.value = data.token
    user.value = data.user
    restaurant.value = data.restaurant
  }

  function clear() {
    localStorage.removeItem('pf_account_token')
    localStorage.removeItem('pf_account_user')
    localStorage.removeItem('pf_account_restaurant')
    token.value = null
    user.value = null
    restaurant.value = null
  }

  async function register(payload) {
    var res = await accountApi.post('/client/register', payload)
    setAccount(res.data)
    return res.data
  }

  // 409 requires_restaurant → devolve a lista para o cliente escolher.
  async function login(payload) {
    try {
      var res = await accountApi.post('/client/login', payload)
      setAccount(res.data)
      return { ok: true }
    } catch (err) {
      if (err.response?.status === 409 && err.response.data?.requires_restaurant) {
        return { ok: false, restaurants: err.response.data.restaurants }
      }
      throw err
    }
  }

  // Cliente que já está na mesa (token da sessão) cria a senha.
  async function upgradeFromTable(payload) {
    var res = await api.post('/client/account', payload)
    setAccount(res.data)
    return res.data
  }

  async function fetchMe() {
    var res = await accountApi.get('/client/me')
    user.value = res.data.user
    restaurant.value = res.data.restaurant
    localStorage.setItem('pf_account_user', JSON.stringify(res.data.user))
    localStorage.setItem('pf_account_restaurant', JSON.stringify(res.data.restaurant))
    return res.data
  }

  async function updateProfile(payload) {
    var res = await accountApi.put('/client/me', payload)
    user.value = res.data.user
    localStorage.setItem('pf_account_user', JSON.stringify(res.data.user))
    return res.data
  }

  async function deleteAccount(password) {
    await accountApi.delete('/client/me', { data: { password: password } })
    clear()
  }

  // Entra na mesa já identificado. A resposta é a mesma de POST /sessions,
  // então o resto do fluxo da mesa segue sem mudanças.
  async function linkTable(tableToken) {
    var res = await accountApi.post('/client/tables/link', { table_token: tableToken })
    return useAuthStore().applySession(res.data)
  }

  async function logout() {
    try { await accountApi.post('/auth/logout') } catch (e) { /* token já inválido */ }
    clear()
  }

  return {
    user,
    restaurant,
    isLoggedIn,
    firstName,
    register,
    login,
    upgradeFromTable,
    fetchMe,
    updateProfile,
    deleteAccount,
    linkTable,
    logout,
  }
})
