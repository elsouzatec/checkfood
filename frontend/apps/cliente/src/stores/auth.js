import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '../api'

export const useAuthStore = defineStore('auth', () => {
  var user = ref(JSON.parse(localStorage.getItem('pf_user') || 'null'))
  var session = ref(JSON.parse(localStorage.getItem('pf_session') || 'null'))
  var table = ref(JSON.parse(localStorage.getItem('pf_table') || 'null'))
  var restaurant = ref(JSON.parse(localStorage.getItem('pf_restaurant') || 'null'))
  var sessionChecked = ref(false)

  var isAuthenticated = computed(function() {
    return !!user.value && !!localStorage.getItem('pf_token')
  })

  var sessionId = computed(function() {
    return session.value?.id || null
  })

  var tableNumber = computed(function() {
    return table.value?.number || null
  })

  var restaurantId = computed(function() {
    return session.value?.restaurant_id || 1
  })

  async function createSession(tableToken, phone, name) {
    var res = await api.post('/sessions', {
      table_token: tableToken,
      phone: phone,
      name: name || undefined,
    })

    localStorage.setItem('pf_token', res.data.token)
    localStorage.setItem('pf_user', JSON.stringify(res.data.user))
    localStorage.setItem('pf_session', JSON.stringify(res.data.session))
    localStorage.setItem('pf_table', JSON.stringify(res.data.table))

    user.value = res.data.user
    session.value = res.data.session
    table.value = res.data.table

    if (res.data.restaurant) {
      localStorage.setItem('pf_restaurant', JSON.stringify(res.data.restaurant))
      restaurant.value = res.data.restaurant
    }

    return res.data
  }

  // Permite outras views (MenuView) atualizarem o restaurante após buscar
  // o menu, garantindo coerência do branding mesmo sem passar pelo /sessions.
  function setRestaurant(r) {
    if (!r) return
    localStorage.setItem('pf_restaurant', JSON.stringify(r))
    restaurant.value = r
  }

  /**
   * Verifica se a sessão ainda está ativa no backend.
   * Se não estiver, limpa tudo e redireciona para a tela inicial.
   * Retorna true se ativa, false se encerrada.
   */
  async function checkSession() {
    if (!localStorage.getItem('pf_token')) {
      sessionChecked.value = true
      return false
    }

    try {
      var res = await api.get('/sessions/check')
      sessionChecked.value = true
      return res.data.active === true
    } catch (err) {
      // 401 = token revogado (interceptor já limpa)
      // 410 = sessão encerrada
      if (err.response && (err.response.status === 410 || err.response.status === 401)) {
        // Guardar session_id para feedback antes de limpar
        var oldSessionId = session.value?.id
        logout()

        // Se sessão foi encerrada (410), redirecionar para feedback
        if (err.response.status === 410 && oldSessionId) {
          localStorage.setItem('pf_feedback_session', oldSessionId)
          window.location.href = '/feedback'
          return false
        }
      }
      sessionChecked.value = true
      return false
    }
  }

  function logout() {
    localStorage.removeItem('pf_token')
    localStorage.removeItem('pf_user')
    localStorage.removeItem('pf_session')
    localStorage.removeItem('pf_table')
    localStorage.removeItem('pf_restaurant')
    localStorage.removeItem('pf_orders')
    user.value = null
    session.value = null
    table.value = null
    restaurant.value = null
  }

  return {
    user,
    session,
    table,
    restaurant,
    isAuthenticated,
    sessionId,
    tableNumber,
    restaurantId,
    sessionChecked,
    createSession,
    checkSession,
    setRestaurant,
    logout,
  }
})
