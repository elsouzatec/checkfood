import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import DashboardView from '../views/DashboardView.vue'
import RestaurantsView from '../views/RestaurantsView.vue'
import RestaurantNewView from '../views/RestaurantNewView.vue'
import RestaurantDetailView from '../views/RestaurantDetailView.vue'

const routes = [
  { path: '/',                    name: 'login',          component: LoginView },
  { path: '/dashboard',           name: 'dashboard',      component: DashboardView,       meta: { requiresAuth: true } },
  { path: '/restaurantes',        name: 'restaurants',    component: RestaurantsView,     meta: { requiresAuth: true } },
  { path: '/restaurantes/novo',   name: 'restaurant-new', component: RestaurantNewView,   meta: { requiresAuth: true } },
  { path: '/restaurantes/:id',    name: 'restaurant',     component: RestaurantDetailView, meta: { requiresAuth: true }, props: true },
]

const router = createRouter({
  history: createWebHistory('/'),
  routes,
})

router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('platform_token')
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
