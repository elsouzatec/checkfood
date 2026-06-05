<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router = useRouter()
const restaurants = ref([])
const loading = ref(true)
const error = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/platform/restaurants')
    restaurants.value = data.restaurants
  } catch (e) {
    error.value = e.response?.data?.message || 'Erro ao carregar restaurantes.'
  } finally {
    loading.value = false
  }
}

function brl(v) {
  return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

onMounted(load)
</script>

<template>
  <div class="page-header">
    <div>
      <div class="page-title">Restaurantes</div>
      <div class="page-subtitle">{{ restaurants.length }} cadastrado(s)</div>
    </div>
    <button class="btn btn-primary" @click="router.push({ name: 'restaurant-new' })">+ Novo restaurante</button>
  </div>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="loading" class="muted">Carregando…</div>

  <div v-else class="card">
    <table class="data-table">
      <thead>
        <tr><th>Nome</th><th>Slug</th><th>Acesso</th><th>Status</th><th>Mensalidade</th><th>Usuários</th></tr>
      </thead>
      <tbody>
        <tr
          v-for="r in restaurants"
          :key="r.id"
          class="row-link"
          @click="router.push({ name: 'restaurant', params: { id: r.id } })"
        >
          <td>{{ r.name }}</td>
          <td class="muted">{{ r.slug }}</td>
          <td>
            <span class="pill amber" v-if="r.deployment_mode === 'intranet'">Intranet</span>
            <span class="pill green" v-else>Domínio</span>
          </td>
          <td>
            <span v-if="r.is_active" class="pill green">Ativo</span>
            <span v-else class="pill red">Suspenso</span>
          </td>
          <td>
            <span v-if="r.billing">
              {{ brl(r.billing.monthly_amount) }}
              <span v-if="r.billing.is_overdue" class="pill red" style="margin-left:6px">vencido</span>
            </span>
            <span v-else class="muted">—</span>
          </td>
          <td class="mono">{{ r.users_count }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
