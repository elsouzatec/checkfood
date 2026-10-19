<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const clients = ref([])
const loading = ref(true)
const error = ref('')
const search = ref('')
const onlyAccounts = ref(false)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)

let searchTimer = null

async function fetchClients() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/clients', {
      params: {
        search: search.value || undefined,
        only_accounts: onlyAccounts.value ? 1 : undefined,
        page: page.value,
      },
    })
    clients.value = data.data
    lastPage.value = data.last_page
    total.value = data.total
  } catch (err) {
    error.value = 'Erro ao carregar clientes.'
  } finally {
    loading.value = false
  }
}

function onSearch() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { page.value = 1; fetchClients() }, 350)
}

function onFilter() {
  page.value = 1
  fetchClients()
}

function goTo(p) {
  page.value = p
  fetchClients()
}

// "Esqueci a senha": sem e-mail/SMS, o gerente redefine e o cliente cria a
// conta de novo com o mesmo telefone. Também desfaz cadastro feito com o
// telefone de outra pessoa.
async function resetAccess(client) {
  const ok = confirm(
    `Redefinir o acesso de "${client.name}"?\n\n` +
    'A senha é apagada e o cliente é desconectado. Ele pode criar a conta de novo com o mesmo telefone.'
  )
  if (!ok) return
  try {
    await api.post(`/admin/clients/${client.id}/reset-account`)
    await fetchClients()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao redefinir acesso.')
  }
}

function formatPhone(p) {
  const v = String(p || '').replace(/\D/g, '')
  if (v.length === 11) return `(${v.slice(0, 2)}) ${v.slice(2, 7)}-${v.slice(7)}`
  if (v.length === 10) return `(${v.slice(0, 2)}) ${v.slice(2, 6)}-${v.slice(6)}`
  return p
}

function formatDate(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleDateString('pt-BR')
}

onMounted(fetchClients)
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Clientes</h1>
        <p class="page-subtitle">Quem pediu pelo QR da mesa. Clientes com conta entram com telefone e senha.</p>
      </div>

      <div class="page-actions">
        <input
          v-model="search"
          type="search"
          class="inline-select search-input"
          placeholder="Buscar nome ou telefone"
          @input="onSearch"
        />
        <label class="form-checkbox only-accounts">
          <input v-model="onlyAccounts" type="checkbox" @change="onFilter" />
          Só com conta
        </label>
      </div>
    </div>

    <div v-if="error" class="error-msg">{{ error }}</div>
    <div v-if="loading && !clients.length" class="loading">Carregando...</div>
    <div v-else-if="!clients.length" class="empty-state">Nenhum cliente encontrado.</div>

    <div v-else class="card">
      <table class="table-grid">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Telefone</th>
            <th>Conta</th>
            <th class="table-align-right">Pedidos</th>
            <th>Última visita</th>
            <th class="table-align-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in clients" :key="c.id">
            <td class="table-strong">{{ c.name }}</td>
            <td class="cell-muted">{{ formatPhone(c.phone) }}</td>
            <td>
              <span class="badge" :class="c.has_account ? 'badge-active' : 'badge-inactive'">
                {{ c.has_account ? 'Com conta' : 'Sem conta' }}
              </span>
            </td>
            <td class="table-align-right cell-muted">{{ c.orders_count }}</td>
            <td class="cell-muted">{{ formatDate(c.last_visit_at) }}</td>
            <td class="table-align-right">
              <button v-if="c.has_account" class="btn btn-secondary btn-sm" @click="resetAccess(c)">
                Redefinir acesso
              </button>
              <span v-else class="em-dash">—</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="lastPage > 1" class="pager">
      <button class="btn btn-secondary btn-sm" :disabled="page <= 1 || loading" @click="goTo(page - 1)">← Anterior</button>
      <span class="pager-info">Página {{ page }} de {{ lastPage }} · {{ total }} clientes</span>
      <button class="btn btn-secondary btn-sm" :disabled="page >= lastPage || loading" @click="goTo(page + 1)">Próxima →</button>
    </div>
  </div>
</template>

<style scoped>
.page-actions {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}
.search-input { min-width: 220px; }
.only-accounts {
  font-size: var(--fs-sm);
  color: var(--admin-text-secondary);
  white-space: nowrap;
}
.cell-muted {
  color: var(--admin-text-muted);
  font-variant-numeric: tabular-nums;
}
.em-dash {
  color: var(--admin-text-muted);
  font-size: var(--fs-xs);
}
.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
  margin-top: 16px;
}
.pager-info {
  font-size: var(--fs-sm);
  color: var(--admin-text-muted);
}
</style>
