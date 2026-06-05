<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()

const showHeader = computed(() => auth.isAuthenticated && route.name !== 'login')

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
    <header v-if="showHeader" class="app-header app-header-3col">
      <div class="header-brand" :title="auth.restaurant?.name || 'PagFood'">
        {{ auth.restaurant?.name || 'PagFood' }}
      </div>

      <div class="header-center">
        <span class="header-badge">Cozinha</span>
      </div>

      <div class="header-right">
        <span v-if="firstName" class="header-user-name" :title="auth.user?.name">
          {{ firstName }}
        </span>
        <button class="header-logout-btn" @click="handleLogout">Sair</button>
      </div>
    </header>

    <router-view />
  </div>
</template>
