<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const users = ref([])
const loading = ref(true)
const showModal = ref(false)
const editing = ref(null)
const saving = ref(false)
const error = ref('')

const form = ref({
  name: '', apelido: '', phone: '', email: '',
  password: '', role: 'waiter', is_active: true,
})

const roleLabels = { manager: 'Gerente', waiter: 'Garçom', kitchen: 'Cozinha', cashier: 'Caixa' }
// Classe de destaque por função (responde ao tema via --admin-data-*)
const roleClass = {
  manager: 'role-manager',
  waiter:  'role-waiter',
  kitchen: 'role-kitchen',
  cashier: 'role-cashier',
}

function applyPhoneMask(e) {
  let v = e.target.value.replace(/\D/g, '').slice(0, 11)
  if (v.length > 6)      v = `(${v.slice(0,2)}) ${v.slice(2,7)}-${v.slice(7)}`
  else if (v.length > 2) v = `(${v.slice(0,2)}) ${v.slice(2)}`
  else if (v.length > 0) v = `(${v}`
  form.value.phone = v
}

async function fetchUsers() {
  try {
    const { data } = await api.get('/admin/users')
    users.value = data.users
  } catch (err) {
    error.value = 'Erro ao carregar equipe.'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  form.value = {
    name: '', apelido: '', phone: '', email: '',
    password: '', role: 'waiter', is_active: true,
  }
  error.value = ''
  showModal.value = true
}

function openEdit(user) {
  editing.value = user
  form.value = {
    name:      user.name,
    apelido:   user.apelido || '',
    phone:     user.phone,
    email:     user.email || '',
    password:  '',
    role:      user.role,
    is_active: user.is_active,
  }
  error.value = ''
  showModal.value = true
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    const payload = { ...form.value }
    if (editing.value && !payload.password) delete payload.password
    if (!payload.email) delete payload.email
    if (!payload.apelido) payload.apelido = null

    if (editing.value) {
      await api.put(`/admin/users/${editing.value.id}`, payload)
    } else {
      await api.post('/admin/users', payload)
    }
    showModal.value = false
    await fetchUsers()
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao salvar.'
  } finally {
    saving.value = false
  }
}

async function toggleActive(user) {
  try {
    await api.put(`/admin/users/${user.id}`, { is_active: !user.is_active })
    await fetchUsers()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao atualizar.')
  }
}

async function remove(user) {
  if (!confirm(`Excluir "${user.name}"?`)) return
  try {
    await api.delete(`/admin/users/${user.id}`)
    await fetchUsers()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao excluir.')
  }
}

onMounted(fetchUsers)
</script>

<template>
  <div>
    <div class="page-header">
      <h1 class="page-title">Equipe</h1>
      <button class="btn btn-primary" @click="openCreate">+ Novo Funcionário</button>
    </div>

    <div v-if="loading" class="loading">Carregando...</div>
    <div v-else-if="!users.length" class="empty-state">Nenhum funcionário cadastrado.</div>

    <div v-else class="card">
      <table class="table-grid">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Apelido</th>
            <th>Telefone</th>
            <th>Função</th>
            <th>Status</th>
            <th class="table-align-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in users" :key="u.id">
            <td class="table-strong">{{ u.name }}</td>
            <td>
              <span v-if="u.apelido" class="apelido-badge">{{ u.apelido }}</span>
              <span v-else class="em-dash">—</span>
            </td>
            <td class="cell-muted">{{ u.phone }}</td>
            <td>
              <span class="role-label" :class="roleClass[u.role]">
                {{ roleLabels[u.role] || u.role }}
              </span>
            </td>
            <td>
              <span
                class="badge status-toggle"
                :class="u.is_active ? 'badge-active' : 'badge-inactive'"
                :title="u.is_active ? 'Clique para desativar' : 'Clique para ativar'"
                @click="toggleActive(u)"
              >
                {{ u.is_active ? 'Ativo' : 'Inativo' }}
              </span>
            </td>
            <td>
              <div class="row-actions">
                <button class="btn btn-secondary btn-sm" @click="openEdit(u)">Editar</button>
                <button class="btn btn-danger btn-sm" @click="remove(u)">Excluir</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Modal ── -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal-box">
        <div class="modal-title">{{ editing ? 'Editar Funcionário' : 'Novo Funcionário' }}</div>
        <div v-if="error" class="error-msg">{{ error }}</div>

        <div class="form-row">
          <div class="form-group">
            <label>Nome completo</label>
            <input v-model="form.name" placeholder="Ex: João Silva" />
          </div>
          <div class="form-group">
            <label>Apelido <span class="field-hint">(para login)</span></label>
            <input
              v-model="form.apelido"
              placeholder="Ex: joao, garcom1"
              autocomplete="off"
            />
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Telefone</label>
            <input
              :value="form.phone"
              type="tel"
              placeholder="(91) 99999-0000"
              inputmode="numeric"
              @input="applyPhoneMask"
            />
          </div>
          <div class="form-group">
            <label>Função</label>
            <select v-model="form.role">
              <option value="waiter">Garçom</option>
              <option value="kitchen">Cozinha</option>
              <option value="manager">Gerente</option>
              <option value="cashier">Caixa</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>Email <span class="field-hint">(opcional)</span></label>
          <input v-model="form.email" type="email" placeholder="email@exemplo.com" />
        </div>

        <div class="form-group">
          <label>
            {{ editing ? 'Nova senha' : 'Senha' }}
            <span v-if="editing" class="field-hint">(deixe vazio para manter)</span>
          </label>
          <input
            v-model="form.password"
            type="password"
            :placeholder="editing ? 'Deixe vazio para manter' : 'Mínimo 4 caracteres'"
          />
        </div>

        <div v-if="editing" class="form-group">
          <label class="form-checkbox">
            <input type="checkbox" v-model="form.is_active" />
            Funcionário ativo
          </label>
        </div>

        <div class="modal-actions">
          <button class="btn btn-secondary" @click="showModal = false">Cancelar</button>
          <button
            class="btn btn-primary"
            :disabled="saving || !form.name || !form.phone || (!editing && !form.password)"
            @click="save"
          >
            {{ saving ? 'Salvando...' : 'Salvar' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.apelido-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: var(--radius-xs);
  background: var(--admin-bg-hover);
  color: var(--admin-text-secondary);
  font-size: var(--fs-xs);
  font-family: monospace;
  letter-spacing: 0.3px;
}

.em-dash {
  color: var(--admin-text-muted);
  font-size: var(--fs-xs);
}

.cell-muted {
  color: var(--admin-text-muted);
  font-variant-numeric: tabular-nums;
}

/* Destaque da função — mapa de 3 cores semânticas, todas theme-aware. */
.role-label {
  font-size: var(--fs-sm);
  font-weight: var(--fw-bold);
}
.role-manager { color: var(--brand-accent); }
.role-waiter  { color: var(--admin-data-blue); }
.role-kitchen { color: var(--admin-data-amber); }
.role-cashier { color: #2A9D8F; }

/* Badge clicável de status (ativo/inativo) */
.status-toggle {
  cursor: pointer;
  transition: transform var(--dur-fast), filter var(--dur-fast);
}
.status-toggle:hover { filter: brightness(1.08); }
.status-toggle:active { transform: scale(0.95); }
</style>
