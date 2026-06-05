<script setup>
import { ref, onMounted, computed, watch } from 'vue'
import api from '../api'

const products = ref([])
const categories = ref([])
const allIngredients = ref([])
const loading = ref(true)
const showModal = ref(false)
const editing = ref(null)
const saving = ref(false)
const uploading = ref(null)
const error = ref('')
const filterCategory = ref('')

// Modal: aba ativa (basico | ficha)
const modalTab = ref('basico')

// Ficha técnica do produto em edição
const productIngredients = ref([])
const fichaForm = ref({ ingredient_id: '', quantity: 0, unit: 'g' })
const fichaError = ref('')
const fichaLoading = ref(false)

const form = ref({
  category_id: '',
  name: '',
  description: '',
  price: '',
  is_active: true,
  sort_order: 0,
})

const filteredProducts = computed(() => {
  if (!filterCategory.value) return products.value
  return products.value.filter(p => p.category_id === Number(filterCategory.value))
})

// Unidade de consumo permitida pelo ingrediente selecionado
const allowedConsumptionUnit = computed(() => {
  const ing = allIngredients.value.find(i => i.id === Number(fichaForm.value.ingredient_id))
  if (!ing) return null
  return ing.unit === 'kg' ? 'g' : ing.unit === 'l' ? 'ml' : 'un'
})

// Auto-corrige a unidade do form quando o ingrediente muda
watch(() => fichaForm.value.ingredient_id, () => {
  if (allowedConsumptionUnit.value) {
    fichaForm.value.unit = allowedConsumptionUnit.value
  }
})

async function fetchData() {
  try {
    const [prodRes, catRes, ingRes] = await Promise.all([
      api.get('/admin/products'),
      api.get('/admin/categories'),
      api.get('/admin/ingredients'),
    ])
    products.value = prodRes.data.products
    categories.value = catRes.data.categories
    allIngredients.value = ingRes.data.ingredients
  } catch (err) {
    error.value = 'Erro ao carregar dados.'
  } finally {
    loading.value = false
  }
}

function formatMoney(val) {
  return Number(val).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function getCategoryName(id) {
  return categories.value.find(c => c.id === id)?.name || '—'
}

function openCreate() {
  editing.value = null
  form.value = {
    category_id: categories.value[0]?.id || '',
    name: '',
    description: '',
    price: '',
    is_active: true,
    sort_order: 0,
  }
  modalTab.value = 'basico'
  productIngredients.value = []
  error.value = ''
  showModal.value = true
}

function openEdit(prod) {
  editing.value = prod
  form.value = {
    category_id: prod.category_id,
    name: prod.name,
    description: prod.description || '',
    price: prod.price,
    is_active: prod.is_active,
    sort_order: prod.sort_order,
  }
  modalTab.value = 'basico'
  error.value = ''
  showModal.value = true
  fetchProductIngredients(prod.id)
}

async function fetchProductIngredients(productId) {
  try {
    const { data } = await api.get('/admin/products/' + productId + '/ingredients')
    productIngredients.value = data.ingredients
  } catch (err) {
    fichaError.value = 'Erro ao carregar ficha técnica.'
  }
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    if (editing.value) {
      await api.put('/admin/products/' + editing.value.id, form.value)
    } else {
      await api.post('/admin/products', form.value)
    }
    showModal.value = false
    await fetchData()
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao salvar.'
  } finally {
    saving.value = false
  }
}

async function remove(prod) {
  if (!confirm('Excluir/desativar "' + prod.name + '"?')) return
  try {
    await api.delete('/admin/products/' + prod.id)
    await fetchData()
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao excluir.')
  }
}

async function uploadImage(prod, event) {
  const file = event.target.files[0]
  if (!file) return
  uploading.value = prod.id
  const fd = new FormData()
  fd.append('image', file)
  try {
    const { data } = await api.post('/admin/products/' + prod.id + '/image', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    prod.image_url = data.image_url
  } catch (err) {
    alert(err.response?.data?.message || 'Erro no upload.')
  } finally {
    uploading.value = null
  }
}

function triggerUpload(prod) {
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = 'image/jpeg,image/png,image/webp'
  input.onchange = (e) => uploadImage(prod, e)
  input.click()
}

// ── Ficha técnica ──
async function attachIngredient() {
  if (!fichaForm.value.ingredient_id || !fichaForm.value.quantity) {
    fichaError.value = 'Preencha o ingrediente e a quantidade.'
    return
  }
  fichaLoading.value = true
  fichaError.value = ''
  try {
    const { data } = await api.post(
      '/admin/products/' + editing.value.id + '/ingredients',
      fichaForm.value
    )
    productIngredients.value = data.ingredients
    fichaForm.value = { ingredient_id: '', quantity: 0, unit: 'g' }
  } catch (err) {
    fichaError.value = err.response?.data?.message || 'Erro ao vincular.'
  } finally {
    fichaLoading.value = false
  }
}

async function detachIngredient(ingredientId) {
  if (!confirm('Remover este ingrediente da ficha técnica?')) return
  try {
    const { data } = await api.delete(
      '/admin/products/' + editing.value.id + '/ingredients/' + ingredientId
    )
    productIngredients.value = data.ingredients
  } catch (err) {
    alert(err.response?.data?.message || 'Erro ao remover.')
  }
}

function getIngredientName(id) {
  return allIngredients.value.find(i => i.id === id)?.name || '—'
}

function formatPivotQty(item) {
  return parseFloat(item.pivot.quantity).toLocaleString('pt-BR', { maximumFractionDigits: 3 }) + ' ' + item.pivot.unit
}

// Ingredientes ainda não vinculados (para o select)
const availableIngredients = computed(() => {
  const linkedIds = productIngredients.value.map(i => i.id)
  return allIngredients.value.filter(i => !linkedIds.includes(i.id))
})

onMounted(fetchData)
</script>

<template>
  <div>
    <div class="page-header">
      <h1 class="page-title">Produtos</h1>

      <div class="page-actions">
        <select v-model="filterCategory" class="inline-select">
          <option value="">Todas as categorias</option>
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
        <button class="btn btn-primary" @click="openCreate">+ Novo Produto</button>
      </div>
    </div>

    <div v-if="loading" class="loading">Carregando...</div>
    <div v-else-if="!filteredProducts.length" class="empty-state">Nenhum produto encontrado.</div>

    <div v-else class="products-grid">
      <div
        v-for="prod in filteredProducts"
        :key="prod.id"
        class="product-card"
      >
        <div
          class="product-img"
          @click="triggerUpload(prod)"
          :title="prod.image_url ? 'Trocar imagem' : 'Adicionar imagem'"
        >
          <img v-if="prod.image_url" :src="prod.image_url" :alt="prod.name" />
          <span v-else class="img-placeholder">📷</span>
          <div class="img-overlay">
            {{ uploading === prod.id ? 'Enviando...' : (prod.image_url ? 'Trocar' : 'Adicionar') }}
          </div>
        </div>

        <div class="product-info">
          <div class="product-name">{{ prod.name }}</div>
          <div class="product-meta">
            <span class="product-cat">{{ getCategoryName(prod.category_id) }}</span>
            <span v-if="prod.is_composite" class="ficha-badge" title="Tem ficha técnica vinculada">📋</span>
            <span class="badge" :class="prod.is_active ? 'badge-active' : 'badge-inactive'">
              {{ prod.is_active ? 'Ativo' : 'Inativo' }}
            </span>
          </div>
          <div class="product-price">{{ formatMoney(prod.price) }}</div>
          <div class="product-actions">
            <button class="btn btn-secondary btn-sm" @click="openEdit(prod)">Editar</button>
            <button class="btn btn-danger btn-sm" @click="remove(prod)">Excluir</button>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Modal ── -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal-box modal-box-tabbed">
        <div class="modal-title">{{ editing ? 'Editar Produto' : 'Novo Produto' }}</div>

        <!-- Tabs (apenas em edição) -->
        <div v-if="editing" class="modal-tabs">
          <button
            class="modal-tab"
            :class="{ active: modalTab === 'basico' }"
            @click="modalTab = 'basico'"
          >Dados</button>
          <button
            class="modal-tab"
            :class="{ active: modalTab === 'ficha' }"
            @click="modalTab = 'ficha'"
          >Ficha Técnica
            <span v-if="productIngredients.length" class="tab-count">{{ productIngredients.length }}</span>
          </button>
        </div>

        <!-- ── Aba: Dados Básicos ── -->
        <div v-if="modalTab === 'basico'">
          <div v-if="error" class="error-msg">{{ error }}</div>

          <div class="form-group">
            <label>Categoria</label>
            <select v-model="form.category_id">
              <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>

          <div class="form-group">
            <label>Nome</label>
            <input v-model="form.name" placeholder="Ex: Hambúrguer Clássico" />
          </div>

          <div class="form-group">
            <label>Descrição</label>
            <textarea v-model="form.description" rows="2" placeholder="Opcional"></textarea>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Preço (R$)</label>
              <input v-model.number="form.price" type="number" step="0.01" min="0" placeholder="0.00" />
            </div>
            <div class="form-group">
              <label>Ordem</label>
              <input v-model.number="form.sort_order" type="number" min="0" />
            </div>
          </div>

          <div class="form-group">
            <label class="form-checkbox">
              <input type="checkbox" v-model="form.is_active" />
              Produto ativo
            </label>
          </div>

          <div class="modal-actions">
            <button class="btn btn-secondary" @click="showModal = false">Cancelar</button>
            <button
              class="btn btn-primary"
              :disabled="saving || !form.name || !form.price"
              @click="save"
            >
              {{ saving ? 'Salvando...' : 'Salvar' }}
            </button>
          </div>
        </div>

        <!-- ── Aba: Ficha Técnica ── -->
        <div v-if="modalTab === 'ficha'">
          <p class="ficha-hint">
            Adicione apenas os ingredientes monitorados. Itens não cadastrados (tempero, óleo, etc) podem ficar de fora.
          </p>

          <div v-if="fichaError" class="error-msg">{{ fichaError }}</div>

          <!-- Lista de vínculos atuais -->
          <div v-if="productIngredients.length" class="ficha-list">
            <div
              v-for="ing in productIngredients"
              :key="ing.id"
              class="ficha-item"
            >
              <div class="ficha-item-info">
                <div class="ficha-item-name">{{ ing.name }}</div>
                <div class="ficha-item-qty">{{ formatPivotQty(ing) }} por porção</div>
              </div>
              <button class="btn-icon-danger" @click="detachIngredient(ing.id)" title="Remover">
                ✕
              </button>
            </div>
          </div>
          <div v-else class="ficha-empty">Nenhum ingrediente vinculado ainda.</div>

          <!-- Adicionar novo -->
          <div class="ficha-add-section">
            <div class="ficha-add-title">Adicionar ingrediente</div>

            <div v-if="!availableIngredients.length" class="ficha-empty">
              Todos os ingredientes já estão vinculados.
            </div>

            <template v-else>
              <div class="form-group">
                <label>Ingrediente</label>
                <select v-model="fichaForm.ingredient_id">
                  <option value="">Selecione...</option>
                  <option v-for="i in availableIngredients" :key="i.id" :value="i.id">
                    {{ i.name }} ({{ i.unit }} em estoque)
                  </option>
                </select>
              </div>

              <div class="form-row">
                <div class="form-group">
                  <label>Quantidade por porção</label>
                  <input
                    v-model.number="fichaForm.quantity"
                    type="number"
                    step="0.001"
                    min="0.001"
                    placeholder="Ex: 200"
                  />
                </div>
                <div class="form-group">
                  <label>Unidade de consumo</label>
                  <input
                    :value="allowedConsumptionUnit || '—'"
                    disabled
                    class="input-disabled"
                  />
                </div>
              </div>

              <button
                class="btn btn-primary"
                :disabled="fichaLoading || !fichaForm.ingredient_id || !fichaForm.quantity"
                @click="attachIngredient"
              >
                {{ fichaLoading ? 'Adicionando...' : '+ Vincular' }}
              </button>
            </template>
          </div>

          <div class="modal-actions">
            <button class="btn btn-secondary" @click="showModal = false">Fechar</button>
          </div>
        </div>

      </div>
    </div>
  </div>
</template>

<style scoped>
/* ── Grid de produtos ── */
.products-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 14px;
}

.product-card {
  background: var(--admin-bg-card);
  border: 1px solid var(--admin-border);
  border-radius: var(--radius);
  overflow: hidden;
  transition: border-color var(--dur-fast), background var(--dur-normal);
  display: flex;
  flex-direction: column;
}
.product-card:hover { border-color: var(--admin-border-hover); }

.product-img {
  width: 100%;
  height: 110px;
  background: var(--admin-bg-input);
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  cursor: pointer;
  overflow: hidden;
  flex-shrink: 0;
}
.product-img img { width: 100%; height: 100%; object-fit: cover; }
.img-placeholder { font-size: 32px; opacity: 0.35; filter: grayscale(1); }
.img-overlay {
  position: absolute;
  inset: 0;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #FFFFFF;
  font-size: var(--fs-xs);
  font-weight: var(--fw-bold);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  opacity: 0;
  transition: opacity var(--dur-fast);
}
.product-img:hover .img-overlay { opacity: 1; }

.product-info {
  padding: 12px 14px 14px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex: 1;
}
.product-name {
  font-weight: var(--fw-semibold);
  font-size: var(--fs-md);
  color: var(--admin-text-primary);
  line-height: 1.3;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  min-height: 36px;
}
.product-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  flex-wrap: wrap;
}
.product-cat {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ficha-badge {
  font-size: 13px;
  cursor: help;
}
.product-price {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: var(--admin-data-teal);
  font-variant-numeric: tabular-nums;
  margin-top: 2px;
  letter-spacing: -0.3px;
}
.product-actions {
  display: flex;
  gap: 6px;
  margin-top: 4px;
}
.product-actions .btn { flex: 1; }

/* ── Modal com tabs ── */
.modal-box-tabbed {
  max-width: 560px;
}

.modal-tabs {
  display: flex;
  gap: 4px;
  border-bottom: 1px solid var(--admin-border);
  margin: 0 -22px 18px;
  padding: 0 22px;
}
.modal-tab {
  padding: 10px 16px;
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--admin-text-muted);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  font-family: inherit;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-bottom: -1px;
}
.modal-tab.active {
  color: var(--admin-data-teal);
  border-bottom-color: var(--admin-data-teal);
}
.tab-count {
  background: var(--admin-data-teal);
  color: #fff;
  font-size: 10px;
  font-weight: var(--fw-bold);
  padding: 1px 7px;
  border-radius: 10px;
  min-width: 18px;
  text-align: center;
}

/* ── Ficha Técnica ── */
.ficha-hint {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  margin-bottom: 14px;
  line-height: 1.4;
}

.ficha-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 16px;
}
.ficha-item {
  display: flex;
  align-items: center;
  background: var(--admin-bg-input);
  border-radius: var(--radius-sm);
  padding: 10px 12px;
  gap: 10px;
}
.ficha-item-info { flex: 1; min-width: 0; }
.ficha-item-name {
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  color: var(--admin-text-primary);
}
.ficha-item-qty {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  font-variant-numeric: tabular-nums;
}
.btn-icon-danger {
  background: transparent;
  border: 1px solid var(--admin-border);
  color: #c0392b;
  width: 28px;
  height: 28px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 13px;
  font-family: inherit;
  flex-shrink: 0;
}
.btn-icon-danger:hover {
  background: rgba(192, 57, 43, 0.1);
  border-color: #c0392b;
}

.ficha-empty {
  text-align: center;
  padding: 18px;
  color: var(--admin-text-muted);
  font-size: var(--fs-sm);
  background: var(--admin-bg-input);
  border-radius: var(--radius-sm);
  margin-bottom: 16px;
}

.ficha-add-section {
  border-top: 1px solid var(--admin-border);
  padding-top: 16px;
  margin-top: 8px;
}
.ficha-add-title {
  font-size: var(--fs-sm);
  font-weight: var(--fw-bold);
  color: var(--admin-text-primary);
  margin-bottom: 10px;
}

.input-disabled {
  background: var(--admin-bg-input) !important;
  color: var(--admin-text-muted) !important;
  cursor: not-allowed;
}
</style>
