<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../api'

const ingredients = ref([])
const loading = ref(true)
const showModal = ref(false)
const editing = ref(null)
const saving = ref(false)
const error = ref('')
const filter = ref('all') // all | ok | alerta | zerado

const form = ref({
  name: '',
  unit: 'kg',
  stock_quantity: 0,
  min_quantity: 0,
})

const filtered = computed(() => {
  if (filter.value === 'all') return ingredients.value
  return ingredients.value.filter(i => i.stock_status === filter.value)
})

const counts = computed(() => {
  const c = { ok: 0, alerta: 0, zerado: 0 }
  ingredients.value.forEach(i => { c[i.stock_status] = (c[i.stock_status] || 0) + 1 })
  return c
})

const unitLabels = { kg: 'Quilograma (kg)', l: 'Litro (l)', un: 'Unidade (un)' }
const statusLabels = { ok: 'Normal', alerta: 'Alerta', zerado: 'Esgotado' }

async function fetchIngredients() {
  loading.value = true
  try {
    const { data } = await api.get('/admin/ingredients')
    ingredients.value = data.ingredients
  } catch (err) {
    error.value = 'Erro ao carregar ingredientes.'
  } finally {
    loading.value = false
  }
}

function openCreate() {
  editing.value = null
  form.value = { name: '', unit: 'kg', stock_quantity: 0, min_quantity: 0 }
  error.value = ''
  showModal.value = true
}

function openEdit(ing) {
  editing.value = ing
  form.value = {
    name: ing.name,
    unit: ing.unit,
    stock_quantity: parseFloat(ing.stock_quantity),
    min_quantity: parseFloat(ing.min_quantity),
  }
  error.value = ''
  showModal.value = true
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    if (editing.value) {
      await api.put('/admin/ingredients/' + editing.value.id, form.value)
    } else {
      await api.post('/admin/ingredients', form.value)
    }
    showModal.value = false
    await fetchIngredients()
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao salvar.'
  } finally {
    saving.value = false
  }
}

async function remove(ing) {
  if (!confirm('Excluir o ingrediente "' + ing.name + '"? Produtos vinculados terão a ficha técnica afetada.')) return
  try {
    await api.delete('/admin/ingredients/' + ing.id)
    await fetchIngredients()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao excluir.')
  }
}

function formatQty(qty, unit) {
  return parseFloat(qty).toLocaleString('pt-BR', { maximumFractionDigits: 3 }) + ' ' + unit
}

onMounted(fetchIngredients)
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Estoque</h1>
        <p class="page-subtitle">Ingredientes monitorados. O abate ocorre na aprovação dos pedidos.</p>
      </div>
      <button class="btn btn-primary" @click="openCreate">+ Novo Ingrediente</button>
    </div>

    <!-- Filtros / contadores -->
    <div class="stock-filters">
      <button
        class="stock-filter"
        :class="{ active: filter === 'all' }"
        @click="filter = 'all'"
      >
        Todos <span class="stock-count">{{ ingredients.length }}</span>
      </button>
      <button
        class="stock-filter status-ok"
        :class="{ active: filter === 'ok' }"
        @click="filter = 'ok'"
      >
        🟢 Normal <span class="stock-count">{{ counts.ok || 0 }}</span>
      </button>
      <button
        class="stock-filter status-alerta"
        :class="{ active: filter === 'alerta' }"
        @click="filter = 'alerta'"
      >
        🟡 Alerta <span class="stock-count">{{ counts.alerta || 0 }}</span>
      </button>
      <button
        class="stock-filter status-zerado"
        :class="{ active: filter === 'zerado' }"
        @click="filter = 'zerado'"
      >
        🔴 Esgotado <span class="stock-count">{{ counts.zerado || 0 }}</span>
      </button>
    </div>

    <div v-if="loading" class="loading">Carregando...</div>
    <div v-else-if="!filtered.length" class="empty-state">
      {{ filter === 'all' ? 'Nenhum ingrediente cadastrado.' : 'Nenhum ingrediente neste filtro.' }}
    </div>

    <div v-else class="ingredients-table">
      <div
        v-for="ing in filtered"
        :key="ing.id"
        class="ingredient-row"
        :class="'row-' + ing.stock_status"
      >
        <div class="ingredient-status-dot" :class="'dot-' + ing.stock_status"></div>

        <div class="ingredient-info">
          <div class="ingredient-name">{{ ing.name }}</div>
          <div class="ingredient-meta">
            <span class="ingredient-stock">{{ formatQty(ing.stock_quantity, ing.unit) }}</span>
            <span class="ingredient-min">mín. {{ formatQty(ing.min_quantity, ing.unit) }}</span>
            <span class="status-pill" :class="'pill-' + ing.stock_status">{{ statusLabels[ing.stock_status] }}</span>
          </div>
        </div>

        <div class="ingredient-actions">
          <button class="btn btn-secondary btn-sm" @click="openEdit(ing)">Editar</button>
          <button class="btn btn-danger btn-sm" @click="remove(ing)">Excluir</button>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal-box">
        <div class="modal-title">{{ editing ? 'Editar Ingrediente' : 'Novo Ingrediente' }}</div>
        <div v-if="error" class="error-msg">{{ error }}</div>

        <div class="form-group">
          <label>Nome</label>
          <input v-model="form.name" placeholder="Ex: Pescada amarela" />
        </div>

        <div class="form-group">
          <label>Unidade de cadastro</label>
          <select v-model="form.unit" :disabled="editing && editing.products_count > 0">
            <option v-for="(label, u) in unitLabels" :key="u" :value="u">{{ label }}</option>
          </select>
          <p class="hint">
            kg / l permitem consumo em gramas / mililitros nos produtos.
            Unidade aceita só consumo em unidades.
          </p>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>Estoque atual</label>
            <input
              v-model.number="form.stock_quantity"
              type="number"
              step="0.001"
              min="0"
            />
          </div>
          <div class="form-group">
            <label>Estoque mínimo</label>
            <input
              v-model.number="form.min_quantity"
              type="number"
              step="0.001"
              min="0"
            />
          </div>
        </div>

        <div class="modal-actions">
          <button class="btn btn-secondary" @click="showModal = false">Cancelar</button>
          <button
            class="btn btn-primary"
            :disabled="saving || !form.name"
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
/* ── Filtros ── */
.stock-filters {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.stock-filter {
  padding: 7px 14px;
  border-radius: var(--radius-pill);
  border: 1.5px solid var(--admin-border);
  background: var(--admin-bg-card);
  color: var(--admin-text-secondary);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  font-family: inherit;
  transition: all var(--dur-fast);
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.stock-filter:hover { border-color: var(--admin-border-hover); }
.stock-filter.active {
  background: var(--admin-data-teal);
  border-color: var(--admin-data-teal);
  color: #fff;
}
.stock-count {
  background: rgba(255, 255, 255, 0.15);
  padding: 1px 8px;
  border-radius: 10px;
  font-size: 11px;
  min-width: 22px;
  text-align: center;
}
.stock-filter:not(.active) .stock-count {
  background: var(--admin-bg-input);
  color: var(--admin-text-muted);
}

/* ── Tabela ── */
.ingredients-table {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.ingredient-row {
  background: var(--admin-bg-card);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius);
  padding: 14px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  transition: border-color var(--dur-fast);
}
.ingredient-row:hover { border-color: var(--admin-border-hover); }

.row-alerta { border-left: 4px solid #d4a017; }
.row-zerado { border-left: 4px solid #c0392b; }

.ingredient-status-dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}
.dot-ok { background: #27ae60; }
.dot-alerta { background: #d4a017; }
.dot-zerado { background: #c0392b; }

.ingredient-info {
  flex: 1;
  min-width: 0;
}
.ingredient-name {
  font-weight: var(--fw-semibold);
  color: var(--admin-text-primary);
  font-size: var(--fs-md);
  margin-bottom: 4px;
}
.ingredient-meta {
  display: flex;
  align-items: center;
  gap: 14px;
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  flex-wrap: wrap;
}
.ingredient-stock {
  font-weight: var(--fw-bold);
  color: var(--admin-text-primary);
  font-variant-numeric: tabular-nums;
}
.ingredient-min { font-variant-numeric: tabular-nums; }

.status-pill {
  padding: 2px 9px;
  border-radius: var(--radius-pill);
  font-size: 11px;
  font-weight: var(--fw-bold);
  text-transform: uppercase;
  letter-spacing: 0.3px;
}
.pill-ok      { background: rgba(39, 174, 96, 0.15);  color: #27ae60; }
.pill-alerta  { background: rgba(212, 160, 23, 0.15); color: #d4a017; }
.pill-zerado  { background: rgba(192, 57, 43, 0.15);  color: #c0392b; }

.ingredient-actions {
  display: flex;
  gap: 6px;
  flex-shrink: 0;
}

@media (max-width: 600px) {
  .ingredient-row {
    flex-wrap: wrap;
  }
  .ingredient-actions {
    width: 100%;
    margin-top: 8px;
  }
  .ingredient-actions .btn { flex: 1; }
}
</style>
