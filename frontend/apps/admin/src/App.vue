<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'
import api from './api'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const showLayout = computed(() => route.name !== 'login')

// Badge de alertas de estoque
const stockAlertCount = ref(0)
let stockAlertTimer = null

const navItems = [
  { name: 'dashboard',  label: 'Dashboard',     icon: '📊' },
  { name: 'categories', label: 'Categorias',    icon: '📂' },
  { name: 'products',   label: 'Produtos',      icon: '🍔' },
  { name: 'ingredients',label: 'Estoque',       icon: '📦' },
  { name: 'tables',     label: 'Mesas',         icon: '🪑' },
  { name: 'staff',      label: 'Equipe',        icon: '👥' },
  { name: 'performance',label: 'Desempenho',    icon: '📈' },
  { name: 'team-performance', label: 'Equipe (KPIs)', icon: '🏃' },
  { name: 'feedbacks',  label: 'Feedbacks',     icon: '⭐' },
  { name: 'printing',   label: 'Impressão',     icon: '🖨' },
  { name: 'settings',   label: 'Configurações', icon: '⚙️' },
]

/* ── Tema (dark/light) ── */
const THEME_KEY = 'pf_admin_theme'
const theme = ref('dark')

function applyTheme(value) {
  document.documentElement.setAttribute('data-theme', value)
  theme.value = value
}

function toggleTheme() {
  const next = theme.value === 'dark' ? 'light' : 'dark'
  localStorage.setItem(THEME_KEY, next)
  applyTheme(next)
}

async function fetchStockAlerts() {
  if (!auth.isAuthenticated) {
    stockAlertCount.value = 0
    return
  }
  try {
    const { data } = await api.get('/admin/ingredients/alerts')
    stockAlertCount.value = data.count || 0
  } catch (e) {
    // silencioso — badge desaparece
    stockAlertCount.value = 0
  }
}

onMounted(() => {
  const saved = localStorage.getItem(THEME_KEY)
  applyTheme(saved === 'light' ? 'light' : 'dark')

  // Polling leve de alertas a cada 60s, mais um fetch imediato
  fetchStockAlerts()
  stockAlertTimer = setInterval(fetchStockAlerts, 60000)
})

async function handleLogout() {
  if (stockAlertTimer) clearInterval(stockAlertTimer)
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div v-if="!showLayout">
    <router-view />
  </div>

  <div v-else class="admin-layout">
    <aside class="sidebar">
      <div class="sidebar-header">
        <div class="logo" :title="auth.restaurant?.name || 'PagFood'">
          {{ auth.restaurant?.name || 'PagFood' }}
        </div>
        <span class="role-badge">Admin</span>
      </div>

      <nav class="sidebar-nav">
        <router-link
          v-for="item in navItems"
          :key="item.name"
          :to="{ name: item.name }"
          class="nav-item"
          :class="{ active: route.name === item.name }"
        >
          <span class="nav-icon">{{ item.icon }}</span>
          <span class="nav-label">{{ item.label }}</span>
          <span
            v-if="item.name === 'ingredients' && stockAlertCount > 0"
            class="nav-badge"
            :title="stockAlertCount + ' ingrediente(s) em alerta ou esgotado(s)'"
          >
            {{ stockAlertCount }}
          </span>
        </router-link>
      </nav>

      <div class="sidebar-footer">
        <div class="user-info">
          <span class="user-name">{{ auth.user?.name }}</span>
          <span class="user-role-text">Gerente</span>
        </div>

        <div class="sidebar-actions">
          <button
            class="theme-toggle-btn"
            :title="theme === 'dark' ? 'Mudar para tema claro' : 'Mudar para tema escuro'"
            @click="toggleTheme"
          >
            {{ theme === 'dark' ? '☀️' : '🌙' }}
          </button>
          <button class="logout-btn" @click="handleLogout">Sair</button>
        </div>
      </div>
    </aside>

    <main class="main-content">
      <router-view @stock-changed="fetchStockAlerts" />
    </main>
  </div>
</template>

<style scoped>
.nav-item {
  position: relative;
}
.nav-badge {
  position: absolute;
  right: 12px;
  top: 50%;
  transform: translateY(-50%);
  background: #c0392b;
  color: #fff;
  border-radius: 10px;
  padding: 1px 7px;
  font-size: 11px;
  font-weight: 700;
  min-width: 20px;
  text-align: center;
  font-variant-numeric: tabular-nums;
  font-family: inherit;
}
</style>
