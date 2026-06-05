<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const categories = ref([])
const loading = ref(true)
const showModal = ref(false)
const editing = ref(null)
const saving = ref(false)
const error = ref('')

const form = ref({ name: '', description: '', preparation_area: 'cozinha', sort_order: 0, is_active: true })

const areaLabels = { cozinha: 'Cozinha', bar: 'Bar', lanchonete: 'Lanchonete' }

async function fetchCategories() {
  try {
    const { data } = await api.get('/admin/categories')
    categories.value = data.categories
  } catch (err) {
    error.value = 'Erro ao carregar categorias.'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  form.value = { name: '', description: '', preparation_area: 'cozinha', sort_order: 0, is_active: true }
  error.value = ''
  showModal.value = true
}

function openEdit(cat) {
  editing.value = cat
  form.value = {
    name: cat.name,
    description: cat.description || '',
    preparation_area: cat.preparation_area,
    sort_order: cat.sort_order,
    is_active: cat.is_active,
  }
  error.value = ''
  showModal.value = true
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    if (editing.value) {
      await api.put(`/admin/categories/${editing.value.id}`, form.value)
    } else {
      await api.post('/admin/categories', form.value)
    }
    showModal.value = false
    await fetchCategories()
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao salvar.'
  } finally {
    saving.value = false
  }
}

async function remove(cat) {
  if (!confirm(`Excluir categoria "${cat.name}"?`)) return
  try {
    await api.delete(`/admin/categories/${cat.id}`)
    await fetchCategories()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao excluir.')
  }
}

onMounted(fetchCategories)
</script>

<template>
  <div>
    <div class="page-header">
      <h1 class="page-title">Categorias</h1>
      <button class="btn btn-primary" @click="openCreate">+ Nova Categoria</button>
    </div>

    <div v-if="loading" class="loading">Carregando...</div>
    <div v-else-if="!categories.length" class="empty-state">Nenhuma categoria cadastrada.</div>

    <div v-else class="card">
      <table class="table-grid">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Área</th>
            <th class="table-align-right">Produtos</th>
            <th class="table-align-right">Ordem</th>
            <th>Status</th>
            <th class="table-align-right">Ações</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="cat in categories" :key="cat.id">
            <td class="table-strong">{{ cat.name }}</td>
            <td>{{ areaLabels[cat.preparation_area] || cat.preparation_area }}</td>
            <td class="table-align-right table-num">{{ cat.products_count }}</td>
            <td class="table-align-right table-num">{{ cat.sort_order }}</td>
            <td>
              <span class="badge" :class="cat.is_active ? 'badge-active' : 'badge-inactive'">
                {{ cat.is_active ? 'Ativa' : 'Inativa' }}
              </span>
            </td>
            <td>
              <div class="row-actions">
                <button class="btn btn-secondary btn-sm" @click="openEdit(cat)">Editar</button>
                <button class="btn btn-danger btn-sm" @click="remove(cat)">Excluir</button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- ── Modal ── -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal-box">
        <div class="modal-title">{{ editing ? 'Editar Categoria' : 'Nova Categoria' }}</div>
        <div v-if="error" class="error-msg">{{ error }}</div>

        <div class="form-group">
          <label>Nome</label>
          <input v-model="form.name" placeholder="Ex: Bebidas" />
        </div>

        <div class="form-group">
          <label>Descrição</label>
          <input v-model="form.description" placeholder="Opcional" />
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Área de Preparo</label>
            <select v-model="form.preparation_area">
              <option value="cozinha">Cozinha</option>
              <option value="bar">Bar</option>
              <option value="lanchonete">Lanchonete</option>
            </select>
          </div>
          <div class="form-group">
            <label>Ordem</label>
            <input v-model.number="form.sort_order" type="number" min="0" />
          </div>
        </div>

        <div class="form-group">
          <label class="form-checkbox">
            <input type="checkbox" v-model="form.is_active" />
            Categoria ativa
          </label>
        </div>

        <div class="modal-actions">
          <button class="btn btn-secondary" @click="showModal = false">Cancelar</button>
          <button class="btn btn-primary" :disabled="saving || !form.name" @click="save">
            {{ saving ? 'Salvando...' : 'Salvar' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
