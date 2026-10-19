import { createRouter, createWebHistory } from 'vue-router'
import LoginView      from '../views/LoginView.vue'
import QueueView      from '../views/QueueView.vue'
import SessionView    from '../views/SessionView.vue'
import HistoryView    from '../views/HistoryView.vue'
import FechamentoView from '../views/FechamentoView.vue'
import DeliveriesView from '../views/DeliveriesView.vue'
import { useShiftStore } from '../stores/shift'

const routes = [
  { path: '/',            name: 'login',       component: LoginView },
  { path: '/fila',        name: 'queue',       component: QueueView,      meta: { requiresAuth: true, requiresShift: true } },
  { path: '/sessao/:id',  name: 'session',     component: SessionView,    meta: { requiresAuth: true, requiresShift: true } },
  { path: '/historico',   name: 'history',     component: HistoryView,    meta: { requiresAuth: true, requiresShift: true } },
  { path: '/entregas',    name: 'deliveries',  component: DeliveriesView, meta: { requiresAuth: true, requiresShift: true } },
  { path: '/fechamento',  name: 'fechamento',  component: FechamentoView, meta: { requiresAuth: true, requiresShift: true } },
]

const router = createRouter({
  history: createWebHistory('/caixa/'),
  routes,
})

router.beforeEach(async (to, from, next) => {
  const hasToken = !!localStorage.getItem('caixa_token')

  if (to.meta.requiresAuth && !hasToken) {
    next({ name: 'login' })
    return
  }

  if (to.meta.requiresShift && hasToken) {
    const shift = useShiftStore()
    // Refresh quando vier de fora do app (F5) — sem turno conhecido em memória
    if (!shift.shift) {
      try { await shift.fetch() } catch (e) { /* tratado abaixo */ }
    }
    if (!shift.isOpen) {
      next({ name: 'login' })
      return
    }
  }

  next()
})

export default router
