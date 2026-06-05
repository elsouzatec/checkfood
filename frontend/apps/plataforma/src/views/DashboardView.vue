<script setup>
import { ref, computed, onMounted } from 'vue'
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
    error.value = e.response?.data?.message || 'Erro ao carregar dados.'
  } finally {
    loading.value = false
  }
}

const total = computed(() => restaurants.value.length)
const active = computed(() => restaurants.value.filter(r => r.is_active).length)
const suspended = computed(() => restaurants.value.filter(r => !r.is_active).length)
const overdue = computed(() => restaurants.value.filter(r => r.billing?.is_overdue).length)
const mrr = computed(() =>
  restaurants.value
    .filter(r => r.billing && r.billing.status === 'active')
    .reduce((sum, r) => sum + Number(r.billing.monthly_amount || 0), 0)
)

function brl(v) {
  return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

onMounted(load)
</script>

<template>
  <div class="page-header">
    <div>
      <div class="page-title">Visão geral</div>
      <div class="page-subtitle">Resumo da plataforma</div>
    </div>
    <button class="btn btn-primary" @click="router.push({ name: 'restaurant-new' })">+ Novo restaurante</button>
  </div>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="loading" class="muted">Carregando…</div>

  <template v-else>
    <div class="kpi-grid">
      <div class="kpi-card"><div class="kpi-value">{{ total }}</div><div class="kpi-label">Restaurantes</div></div>
      <div class="kpi-card"><div class="kpi-value ok">{{ active }}</div><div class="kpi-label">Ativos</div></div>
      <div class="kpi-card"><div class="kpi-value" :class="{ danger: suspended > 0 }">{{ suspended }}</div><div class="kpi-label">Suspensos</div></div>
      <div class="kpi-card"><div class="kpi-value" :class="{ danger: overdue > 0 }">{{ overdue }}</div><div class="kpi-label">Inadimplentes</div></div>
      <div class="kpi-card"><div class="kpi-value">{{ brl(mrr) }}</div><div class="kpi-label">Receita mensal (ativos)</div></div>
    </div>

    <div class="card">
      <div class="card-title">Restaurantes</div>
      <table class="data-table">
        <thead>
          <tr><th>Nome</th><th>Slug</th><th>Status</th><th>Mensalidade</th></tr>
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
          </tr>
        </tbody>
      </table>
    </div>
  </template>
</template>
