import { createRouter, createWebHistory } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import KitchenView from '../views/KitchenView.vue'

const routes = [
  { path: '/', name: 'login', component: LoginView },
  { path: '/painel', name: 'kitchen', component: KitchenView, meta: { requiresAuth: true } },
]

const router = createRouter({
  history: createWebHistory('/cozinha/'),
  routes,
})

router.beforeEach((to, from, next) => {
  if (to.meta.requiresAuth && !localStorage.getItem('auth_token')) {
    next({ name: 'login' })
    return
  }
  if (to.name === 'login' && localStorage.getItem('auth_token')) {
    next({ name: 'kitchen' })
    return
  }
  next()
})

export default router
