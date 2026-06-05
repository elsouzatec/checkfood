<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const showNav = computed(() => auth.isAuthenticated && route.name !== 'login')

const firstName = computed(() => {
  const n = auth.user?.name || ''
  return n.split(' ')[0] || ''
})

async function handleLogout() {
  await auth.logout()
  router.push('/')
}
</script>

<template>
  <div>
    <!-- Header 3 colunas: Empresa · Setor · Nome + Sair -->
    <header v-if="showNav" class="app-header app-header-3col">
      <div class="header-brand" :title="auth.restaurant?.name || 'PagFood'">
        {{ auth.restaurant?.name || 'PagFood' }}
      </div>

      <div class="header-center">
        <span class="header-badge">Garçom</span>
      </div>

      <div class="header-right">
        <span v-if="firstName" class="header-user-name" :title="auth.user?.name">
          {{ firstName }}
        </span>
        <button class="header-logout-btn" @click="handleLogout">Sair</button>
      </div>
    </header>

    <router-view />

    <nav v-if="showNav" class="bottom-nav">
      <router-link
        to="/mesas"
        class="nav-tab"
        :class="{ active: route.name === 'dashboard' || route.name === 'table-orders' }"
      >
        <span class="nav-tab-icon">🪑</span>
        Mesas
      </router-link>
      <router-link
        to="/prontos"
        class="nav-tab"
        :class="{ active: route.name === 'ready' }"
      >
        <span class="nav-tab-icon">✅</span>
        Prontos
      </router-link>
    </nav>
  </div>
</template>
