import { createRouter, createWebHistory } from 'vue-router'
import api from '../api'
import QrEntryView       from '../views/QrEntryView.vue'
import MenuView          from '../views/MenuView.vue'
import CartView          from '../views/CartView.vue'
import OrderTrackingView from '../views/OrderTrackingView.vue'
import AccountView       from '../views/AccountView.vue'
import FeedbackView      from '../views/FeedbackView.vue'
import NoTableView       from '../views/NoTableView.vue'
import PaymentView       from '../views/PaymentView.vue'

const routes = [
  { path: '/mesa/:token',  name: 'qr-entry', component: QrEntryView },
  { path: '/cardapio',     name: 'menu',     component: MenuView,          meta: { requiresSession: true } },
  { path: '/carrinho',     name: 'cart',     component: CartView,          meta: { requiresSession: true } },
  { path: '/pedidos',      name: 'orders',   component: OrderTrackingView, meta: { requiresSession: true } },
  { path: '/conta',        name: 'account',  component: AccountView,       meta: { requiresSession: true } },
  { path: '/pagamento',    name: 'payment',  component: PaymentView,       meta: { requiresSession: true } },
  { path: '/feedback',     name: 'feedback', component: FeedbackView },
  { path: '/sem-mesa',     name: 'no-table', component: NoTableView },
  { path: '/:pathMatch(.*)*', redirect: '/sem-mesa' },
]

const router = createRouter({
  history: createWebHistory('/'),
  routes,
})

var sessionCheckDone = false

router.beforeEach(async (to, from, next) => {
  var token = localStorage.getItem('pf_token')

  if (!token && to.meta.requiresSession) {
    next({ name: 'no-table' })
    return
  }

  if (token && !sessionCheckDone && to.meta.requiresSession) {
    sessionCheckDone = true
    try {
      var res = await api.get('/sessions/check')
      var sessionStatus = res.data.session_status

      if (sessionStatus === 'closing' && to.name !== 'payment') {
        next({ name: 'payment' })
        return
      }

      if (sessionStatus === 'active' && to.name === 'payment') {
        next({ name: 'menu' })
        return
      }

    } catch (err) {
      if (err.response) {

        if (err.response.status === 410) {
          if (to.name !== 'feedback') {
            // ── CORREÇÃO BUG 1 ──────────────────────────────────────────
            // Salvar session_id ANTES de limpar qualquer dado do localStorage.
            // Antes, clearSession() era chamada primeiro e apagava pf_session,
            // tornando impossível salvar pf_feedback_session logo depois.
            try {
              var stored = localStorage.getItem('pf_session')
              if (stored) {
                var parsed = JSON.parse(stored)
                localStorage.setItem('pf_feedback_session', String(parsed.id || ''))
              }
            } catch (e) {}

            // Limpar sessão/mesa/pedidos mas MANTER o token.
            // FeedbackView precisa do token para fazer o POST de /feedbacks.
            // O token só é removido depois que o feedback é enviado
            // (segue o mesmo padrão de PaymentView.scheduleRedirect).
            localStorage.removeItem('pf_user')
            localStorage.removeItem('pf_session')
            localStorage.removeItem('pf_table')
            localStorage.removeItem('pf_orders')
            sessionCheckDone = false

            next({ name: 'feedback' })
          } else {
            // Já está na tela de feedback — apenas deixar passar
            clearSession()
            next()
          }
          return
        }

        if (err.response.status === 401) {
          clearSession()
          next({ name: 'no-table' })
          sessionCheckDone = false
          return
        }
      }
    }
  }

  next()
})

function clearSession() {
  localStorage.removeItem('pf_token')
  localStorage.removeItem('pf_user')
  localStorage.removeItem('pf_session')
  localStorage.removeItem('pf_table')
  localStorage.removeItem('pf_orders')
  sessionCheckDone = false
}

export default router
