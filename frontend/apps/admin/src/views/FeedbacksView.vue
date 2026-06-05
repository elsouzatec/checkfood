<script setup>
import { ref, onMounted, computed } from 'vue'
import api from '../api'

const feedbacks    = ref([])
const summary      = ref(null)
const distribution = ref({})
const loading      = ref(true)
const page         = ref(1)
const lastPage     = ref(1)
const filterRating = ref('')

async function fetchFeedbacks() {
  loading.value = true
  try {
    let params = '?page=' + page.value
    if (filterRating.value) params += '&rating=' + filterRating.value
    const res = await api.get('/admin/feedbacks' + params)
    feedbacks.value    = res.data.feedbacks.data
    lastPage.value     = res.data.feedbacks.last_page
    summary.value      = res.data.summary
    distribution.value = res.data.distribution || {}
  } catch (err) {
    console.error('Erro:', err)
  } finally {
    loading.value = false
  }
}

function stars(n) {
  let out = ''
  for (let i = 0; i < 5; i++) out += i < n ? '★' : '☆'
  return out
}

// Classe de cor para as estrelas (token-based, responde ao tema).
function starClass(n) {
  if (n >= 4) return 'star-positive'
  if (n === 3) return 'star-neutral'
  return 'star-negative'
}

function formatDate(dt) {
  if (!dt) return '—'
  return new Date(dt).toLocaleDateString('pt-BR', {
    day: '2-digit', month: '2-digit', year: '2-digit',
    hour: '2-digit', minute: '2-digit',
  })
}

function setFilter(r) {
  filterRating.value = filterRating.value === r ? '' : r
  page.value = 1
  fetchFeedbacks()
}

function prevPage() {
  if (page.value > 1) { page.value--; fetchFeedbacks() }
}
function nextPage() {
  if (page.value < lastPage.value) { page.value++; fetchFeedbacks() }
}

const barMax = computed(() => {
  const vals = Object.values(distribution.value)
  return vals.length ? Math.max(...vals) : 1
})

function barWidth(r) {
  return ((distribution.value[r] || 0) / barMax.value * 100) + '%'
}

function avgRounded() {
  return Math.round(summary.value?.avg_rating || 0)
}

onMounted(fetchFeedbacks)
</script>

<template>
  <div>
    <div class="page-header">
      <h1 class="page-title">Feedbacks</h1>
    </div>

    <div v-if="loading && !summary" class="loading">Carregando...</div>

    <template v-else>
      <!-- ── Resumo ── -->
      <div v-if="summary" class="fb-summary-grid">
        <div class="summary-card fb-summary-card">
          <div class="fb-big-number" :class="starClass(avgRounded())">
            {{ summary.avg_rating || '—' }}
          </div>
          <div class="fb-summary-label">Média geral</div>
          <div class="fb-summary-stars" :class="starClass(avgRounded())">
            {{ stars(avgRounded()) }}
          </div>
        </div>

        <div class="summary-card fb-summary-card">
          <div class="fb-big-number">{{ summary.total || 0 }}</div>
          <div class="fb-summary-label">Total de avaliações</div>
        </div>

        <div class="summary-card fb-summary-card">
          <div class="fb-big-number star-positive">{{ summary.positive || 0 }}</div>
          <div class="fb-summary-label">Positivas (4-5★)</div>
        </div>

        <div class="summary-card fb-summary-card">
          <div class="fb-big-number star-negative">{{ summary.negative || 0 }}</div>
          <div class="fb-summary-label">Negativas (1-2★)</div>
        </div>
      </div>

      <!-- ── Distribuição por nota (clicável = filtra) ── -->
      <div v-if="Object.keys(distribution).length" class="card dist-card">
        <div
          v-for="r in [5, 4, 3, 2, 1]"
          :key="r"
          class="dist-row"
          :class="{ 'dist-active': filterRating === String(r) }"
          @click="setFilter(String(r))"
        >
          <span class="dist-label" :class="starClass(r)">{{ r }}★</span>
          <div class="dist-bar-bg">
            <div
              class="dist-bar-fill"
              :class="starClass(r)"
              :style="{ width: barWidth(r) }"
            ></div>
          </div>
          <span class="dist-count">{{ distribution[r] || 0 }}</span>
        </div>

        <div v-if="filterRating" class="dist-filter-info">
          Filtrando: <strong>{{ filterRating }}★</strong>
          <button class="btn btn-secondary btn-sm dist-clear-btn" @click="setFilter('')">
            Limpar
          </button>
        </div>
      </div>

      <!-- ── Lista de feedbacks ── -->
      <div class="card fb-list-card">
        <div v-if="!feedbacks.length" class="empty-state">Nenhum feedback encontrado.</div>

        <div v-for="fb in feedbacks" :key="fb.id" class="fb-item">
          <div class="fb-header">
            <div class="fb-stars" :class="starClass(fb.rating)">{{ stars(fb.rating) }}</div>
            <div class="fb-meta">
              <span v-if="fb.user">{{ fb.user.name }}</span>
              <span v-if="fb.table"> · Mesa {{ fb.table.number }}</span>
              <span> · {{ formatDate(fb.created_at) }}</span>
            </div>
          </div>
          <div v-if="fb.comment" class="fb-comment">{{ fb.comment }}</div>
          <div v-else class="fb-no-comment">Sem comentário</div>
        </div>

        <!-- Paginação -->
        <div v-if="lastPage > 1" class="fb-pagination">
          <button class="btn btn-secondary btn-sm" :disabled="page <= 1" @click="prevPage">
            ← Anterior
          </button>
          <span class="fb-page-info">{{ page }} / {{ lastPage }}</span>
          <button class="btn btn-secondary btn-sm" :disabled="page >= lastPage" @click="nextPage">
            Próxima →
          </button>
        </div>
      </div>
    </template>
  </div>
</template>

<style scoped>
/* Paleta de avaliação (token-based, responde ao tema) */
.star-positive { color: var(--status-ready-color); }
.star-neutral  { color: var(--status-received-color); }
.star-negative { color: var(--status-danger-color); }

/* Distribuição — fill da barra usa a mesma paleta por composição
   (o elemento herda .star-positive/etc., então background pega via currentColor). */
.dist-bar-fill {
  background: currentColor;
  /* A classe de cor vai definir o `color` e o background herda via currentColor */
}

/* ── Summary cards específicos ── */
.fb-summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 14px;
  margin-bottom: 16px;
}
.fb-summary-card {
  text-align: center;
  padding: 18px 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
}
.fb-big-number {
  font-size: 28px;
  font-weight: var(--fw-bold);
  color: var(--admin-text-primary);
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
  letter-spacing: -0.5px;
}
.fb-summary-label {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.4px;
  font-weight: var(--fw-semibold);
}
.fb-summary-stars {
  font-size: var(--fs-lg);
  letter-spacing: 1px;
}

/* ── Distribuição ── */
.dist-card { margin-top: 10px; }

.dist-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 7px 8px;
  cursor: pointer;
  border-radius: var(--radius-xs);
  transition: background var(--dur-fast);
}
.dist-row:hover { background: var(--admin-bg-hover); }
.dist-active    { background: var(--admin-bg-hover); }

.dist-label {
  font-size: var(--fs-sm);
  font-weight: var(--fw-bold);
  width: 32px;
  text-align: right;
  font-variant-numeric: tabular-nums;
}
.dist-bar-bg {
  flex: 1;
  height: 12px;
  background: var(--admin-bg-input);
  border-radius: 6px;
  overflow: hidden;
}
.dist-bar-fill {
  height: 100%;
  border-radius: 6px;
  transition: width 0.3s ease;
  min-width: 2px;
}
.dist-count {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  width: 30px;
  font-variant-numeric: tabular-nums;
  text-align: right;
}

.dist-filter-info {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
  margin-top: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* ── Lista ── */
.fb-list-card { margin-top: 10px; }

.fb-item {
  padding: 14px 0;
  border-bottom: 1px solid var(--admin-border);
}
.fb-item:first-child { padding-top: 0; }
.fb-item:last-child  { border-bottom: none; padding-bottom: 0; }

.fb-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 6px;
  flex-wrap: wrap;
}
.fb-stars {
  font-size: var(--fs-lg);
  letter-spacing: 1px;
}
.fb-meta {
  font-size: var(--fs-xs);
  color: var(--admin-text-muted);
}
.fb-comment {
  font-size: var(--fs-md);
  color: var(--admin-text-primary);
  line-height: 1.5;
}
.fb-no-comment {
  font-size: var(--fs-sm);
  color: var(--admin-text-muted);
  font-style: italic;
}

/* ── Paginação ── */
.fb-pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 16px;
  margin-top: 16px;
  padding-top: 12px;
  border-top: 1px solid var(--admin-border);
}
.fb-page-info {
  font-size: var(--fs-sm);
  color: var(--admin-text-muted);
  font-variant-numeric: tabular-nums;
}
</style>
