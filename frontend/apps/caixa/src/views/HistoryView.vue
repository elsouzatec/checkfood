<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router = useRouter()

const sessions   = ref([])
const loading    = ref(true)
const error      = ref('')
const groupMode  = ref('chrono')

const expandedGroups = ref(new Set())

function toggleGroup(key) {
  const next = new Set(expandedGroups.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  expandedGroups.value = next
}
function isExpanded(key) {
  return expandedGroups.value.has(key)
}

function navigateToSession(id) {
  router.push(`/sessao/${id}?origem=historico`)
}

const groupedByWaiter = computed(() => {
  const map = {}
  for (const s of sessions.value) {
    const key = s.waiter || '—'
    if (!map[key]) map[key] = { key, sessions: [], total: 0 }
    map[key].sessions.push(s)
    map[key].total += s.total
  }
  return Object.values(map).sort((a, b) => b.total - a.total)
})

const groupedByTable = computed(() => {
  const map = {}
  for (const s of sessions.value) {
    const key = s.table?.number ?? '?'
    if (!map[key]) map[key] = { key, sessions: [], total: 0 }
    map[key].sessions.push(s)
    map[key].total += s.total
  }
  return Object.values(map).sort((a, b) => Number(a.key) - Number(b.key))
})

const grandTotal = computed(() =>
  sessions.value.reduce((sum, s) => sum + Number(s.total || 0), 0)
)

function formatPrice(v) {
  return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function formatTime(iso) {
  if (!iso) return '—'
  return new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

async function fetchAll() {
  loading.value = true
  error.value   = ''
  try {
    const { data } = await api.get('/caixa/history')
    sessions.value = data.sessions
  } catch {
    error.value = 'Erro ao carregar histórico.'
  } finally {
    loading.value = false
  }
}

onMounted(fetchAll)
</script>

<template>
  <div class="cx-history">
    <!-- Toolbar -->
    <div class="cx-history__toolbar">
      <div class="cx-history__count">
        <span class="cx-history__count-num">{{ sessions.length }}</span>
        <span class="cx-history__count-label">
          atend{{ sessions.length !== 1 ? 'imentos' : 'imento' }} ·
          {{ formatPrice(grandTotal) }}
        </span>
      </div>

      <div class="cx-history__group-tabs">
        <button
          class="cx-filter-pill"
          :class="{ 'cx-filter-pill--active': groupMode === 'chrono' }"
          @click="groupMode = 'chrono'"
        >Horário</button>
        <button
          class="cx-filter-pill"
          :class="{ 'cx-filter-pill--active': groupMode === 'waiter' }"
          @click="groupMode = 'waiter'"
        >Garçom</button>
        <button
          class="cx-filter-pill"
          :class="{ 'cx-filter-pill--active': groupMode === 'table' }"
          @click="groupMode = 'table'"
        >Mesa</button>
        <button class="cx-queue__refresh" title="Atualizar" @click="fetchAll">↻</button>
      </div>
    </div>

    <div v-if="loading" class="cx-history__loading">Carregando...</div>
    <div v-else-if="error" class="cx-msg cx-msg--error">{{ error }}</div>

    <div v-else-if="sessions.length === 0" class="cx-history__empty">
      Nenhuma mesa paga hoje ainda.
    </div>

    <!-- ── Chrono: lista plana ── -->
    <div v-else-if="groupMode === 'chrono'" class="cx-srow-list">
      <button
        v-for="s in sessions"
        :key="s.id"
        class="cx-srow"
        @click="navigateToSession(s.id)"
      >
        <span class="cx-srow__mesa">Mesa {{ s.table?.number ?? '—' }}</span>
        <span
          class="cx-srow__guest"
          :class="{ 'cx-srow__guest--empty': !s.guest_name }"
        >{{ s.guest_name || 'Cliente não identificado' }}</span>
        <span class="cx-srow__waiter">{{ s.waiter || '—' }}</span>
        <span class="cx-srow__time">{{ formatTime(s.closed_at) }}</span>
        <span class="cx-srow__total">{{ formatPrice(s.total) }}</span>
        <span class="cx-srow__chevron" aria-hidden="true">›</span>
      </button>
    </div>

    <!-- ── Por garçom ── -->
    <div v-else-if="groupMode === 'waiter'" class="cx-group-list">
      <div
        v-for="group in groupedByWaiter"
        :key="group.key"
        class="cx-group"
        :class="{ 'cx-group--expanded': isExpanded('waiter:' + group.key) }"
      >
        <button
          type="button"
          class="cx-group__header"
          :aria-expanded="isExpanded('waiter:' + group.key)"
          @click="toggleGroup('waiter:' + group.key)"
        >
          <span class="cx-group__icon" aria-hidden="true">👤</span>
          <span class="cx-group__name">{{ group.key }}</span>
          <span class="cx-group__count">
            {{ group.sessions.length }} mesa{{ group.sessions.length !== 1 ? 's' : '' }}
          </span>
          <span class="cx-group__total">{{ formatPrice(group.total) }}</span>
          <span class="cx-group__chevron" aria-hidden="true">›</span>
        </button>
        <div v-if="isExpanded('waiter:' + group.key)" class="cx-group__body">
          <button
            v-for="s in group.sessions"
            :key="s.id"
            class="cx-srow cx-srow--ingroup"
            @click="navigateToSession(s.id)"
          >
            <span class="cx-srow__mesa">Mesa {{ s.table?.number ?? '—' }}</span>
            <span
              class="cx-srow__guest"
              :class="{ 'cx-srow__guest--empty': !s.guest_name }"
            >{{ s.guest_name || 'Cliente não identificado' }}</span>
            <span class="cx-srow__time">{{ formatTime(s.closed_at) }}</span>
            <span class="cx-srow__total">{{ formatPrice(s.total) }}</span>
            <span class="cx-srow__chevron" aria-hidden="true">›</span>
          </button>
        </div>
      </div>
    </div>

    <!-- ── Por mesa ── -->
    <div v-else class="cx-group-list">
      <div
        v-for="group in groupedByTable"
        :key="group.key"
        class="cx-group"
        :class="{ 'cx-group--expanded': isExpanded('table:' + group.key) }"
      >
        <button
          type="button"
          class="cx-group__header"
          :aria-expanded="isExpanded('table:' + group.key)"
          @click="toggleGroup('table:' + group.key)"
        >
          <span class="cx-group__icon" aria-hidden="true">🪑</span>
          <span class="cx-group__name">Mesa {{ group.key }}</span>
          <span class="cx-group__count">
            {{ group.sessions.length }} atend.
          </span>
          <span class="cx-group__total">{{ formatPrice(group.total) }}</span>
          <span class="cx-group__chevron" aria-hidden="true">›</span>
        </button>
        <div v-if="isExpanded('table:' + group.key)" class="cx-group__body">
          <button
            v-for="s in group.sessions"
            :key="s.id"
            class="cx-srow cx-srow--ingroup cx-srow--by-table"
            @click="navigateToSession(s.id)"
          >
            <span
              class="cx-srow__guest"
              :class="{ 'cx-srow__guest--empty': !s.guest_name }"
            >{{ s.guest_name || 'Cliente não identificado' }}</span>
            <span class="cx-srow__waiter">{{ s.waiter || '—' }}</span>
            <span class="cx-srow__time">{{ formatTime(s.closed_at) }}</span>
            <span class="cx-srow__total">{{ formatPrice(s.total) }}</span>
            <span class="cx-srow__chevron" aria-hidden="true">›</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
