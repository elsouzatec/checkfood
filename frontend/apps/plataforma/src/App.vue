<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from './stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const showLayout = computed(() => route.name !== 'login')

const navItems = [
  { name: 'dashboard',   label: 'Visão geral', icon: '📊' },
  { name: 'restaurants', label: 'Restaurantes', icon: '🏪' },
]

function isActive(name) {
  if (name === 'restaurants') {
    return ['restaurants', 'restaurant', 'restaurant-new'].includes(route.name)
  }
  return route.name === name
}

async function handleLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div v-if="!showLayout">
    <router-view />
  </div>

  <div v-else class="platform-layout">
    <aside class="sidebar">
      <div class="sidebar-header">
        <div class="logo">PagFood</div>
        <span class="role-badge">Plataforma</span>
      </div>

      <nav class="sidebar-nav">
        <router-link
          v-for="item in navItems"
          :key="item.name"
          :to="{ name: item.name }"
          class="nav-item"
          :class="{ active: isActive(item.name) }"
        >
          <span class="nav-icon">{{ item.icon }}</span>
          <span class="nav-label">{{ item.label }}</span>
        </router-link>
      </nav>

      <div class="sidebar-footer">
        <div class="user-info">
          <span class="user-name">{{ auth.user?.name }}</span>
          <span class="user-role-text">Super Admin</span>
        </div>
        <button class="logout-btn" @click="handleLogout">Sair</button>
      </div>
    </aside>

    <main class="main-content">
      <router-view />
    </main>
  </div>
</template>
