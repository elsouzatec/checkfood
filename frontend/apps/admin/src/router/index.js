import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import DashboardView from '../views/DashboardView.vue'
import CategoriesView from '../views/CategoriesView.vue'
import ProductsView from '../views/ProductsView.vue'
import IngredientsView from '../views/IngredientsView.vue'
import TablesView from '../views/TablesView.vue'
import TablesConfigView from '../views/TablesConfigView.vue'
import StaffView from '../views/StaffView.vue'
import ClientsView from '../views/ClientsView.vue'
import SettingsView from '../views/SettingsView.vue'
import PrintingView from '../views/PrintingView.vue'
import PerformanceView from '../views/PerformanceView.vue'
import TeamPerformanceView from '../views/TeamPerformanceView.vue'
import FeedbacksView from '../views/FeedbacksView.vue'

const routes = [
  { path: '/',                  name: 'login',        component: LoginView },
  { path: '/dashboard',         name: 'dashboard',    component: DashboardView,    meta: { requiresAuth: true } },
  { path: '/categorias',        name: 'categories',   component: CategoriesView,   meta: { requiresAuth: true } },
  { path: '/produtos',          name: 'products',     component: ProductsView,     meta: { requiresAuth: true } },
  { path: '/estoque',           name: 'ingredients',  component: IngredientsView,  meta: { requiresAuth: true } },
  { path: '/mesas',             name: 'tables',       component: TablesView,       meta: { requiresAuth: true } },
  { path: '/mesas/configurar',  name: 'tables-config',component: TablesConfigView, meta: { requiresAuth: true } },
  { path: '/equipe',            name: 'staff',        component: StaffView,        meta: { requiresAuth: true } },
  { path: '/clientes',          name: 'clients',      component: ClientsView,      meta: { requiresAuth: true } },
  { path: '/desempenho',        name: 'performance',  component: PerformanceView,  meta: { requiresAuth: true } },
  { path: '/desempenho-equipe', name: 'team-performance', component: TeamPerformanceView, meta: { requiresAuth: true } },
  { path: '/feedbacks',         name: 'feedbacks',    component: FeedbacksView,    meta: { requiresAuth: true } },
  { path: '/impressao',         name: 'printing',     component: PrintingView,     meta: { requiresAuth: true } },
  { path: '/configuracoes',     name: 'settings',     component: SettingsView,     meta: { requiresAuth: true } },
]

const router = createRouter({
  history: createWebHistory('/admin/'),
  routes,
})

router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('admin_token')
  if (to.meta.requiresAuth && !token) {
    next({ name: 'login' })
    return
  }
  if (to.name === 'login' && token) {
    next({ name: 'dashboard' })
    return
  }
  next()
})

export default router
