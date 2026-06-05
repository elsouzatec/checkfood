import { createRouter, createWebHistory } from 'vue-router'
import LoginView       from '../views/LoginView.vue'
import DashboardView   from '../views/DashboardView.vue'
import TableOrdersView from '../views/TableOrdersView.vue'
import ReadyOrdersView from '../views/ReadyOrdersView.vue'
import RetornoView     from '../views/RetornoView.vue'
import NovaMesaView    from '../views/NovaMesaView.vue'
import WaiterMenuView  from '../views/WaiterMenuView.vue'

const routes = [
  { path: '/',               name: 'login',        component: LoginView },
  { path: '/mesas',          name: 'dashboard',    component: DashboardView,   meta: { requiresAuth: true } },
  { path: '/mesas/:id',      name: 'table-orders', component: TableOrdersView, meta: { requiresAuth: true } },
  { path: '/prontos',        name: 'ready',        component: ReadyOrdersView, meta: { requiresAuth: true } },
  { path: '/nova-mesa/:tableId?', name: 'nova-mesa', component: NovaMesaView,  meta: { requiresAuth: true } },
  { path: '/menu/:sessionId', name: 'waiter-menu', component: WaiterMenuView,  meta: { requiresAuth: true } },
  // Callback do terminal POS — não exige auth para suportar redirect do app da adquirente
  { path: '/retorno',        name: 'retorno',      component: RetornoView,     meta: { requiresAuth: true } },
]

const router = createRouter({
  history: createWebHistory('/garcom/'),
  routes,
})

router.beforeEach((to, from, next) => {
  if (to.meta.requiresAuth && !localStorage.getItem('garcom_token')) {
    next({ name: 'login' })
    return
  }
  if (to.name === 'login' && localStorage.getItem('garcom_token')) {
    next({ name: 'dashboard' })
    return
  }
  next()
})

export default router
