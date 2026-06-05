<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '../api'

var route  = useRoute()
var router = useRouter()

var table       = ref(null)
var guestName   = ref('')
var guestPhone  = ref('')
var loading     = ref(false)
var loadingTable = ref(true)
var error       = ref('')

onMounted(async function() {
  var tableId = route.params.tableId
  if (!tableId) {
    loadingTable.value = false
    return
  }
  try {
    var res = await api.get('/tables/' + tableId + '/orders')
    table.value = res.data.table

    // Mesa já tem sessão ativa — vai direto para o menu
    if (res.data.session) {
      router.replace('/menu/' + res.data.session.id + '?tableId=' + tableId)
      return
    }
  } catch (err) {
    error.value = 'Erro ao carregar mesa.'
  } finally {
    loadingTable.value = false
  }
})

async function submit() {
  if (!guestName.value.trim()) {
    error.value = 'Informe o nome do cliente.'
    return
  }

  loading.value = true
  error.value   = ''

  try {
    var payload = {
      table_id:    table.value.id,
      guest_name:  guestName.value.trim(),
      guest_phone: guestPhone.value.trim() || null,
    }

    var res = await api.post('/waiter/sessions', payload)
    var session = res.data.session

    router.push('/menu/' + session.id + '?tableId=' + table.value.id)
  } catch (err) {
    if (err.response?.status === 409) {
      // Sessão já existe — usa ela
      var existingSession = err.response.data.session
      router.push('/menu/' + existingSession.id + '?tableId=' + table.value.id)
    } else {
      error.value = err.response?.data?.message || 'Erro ao abrir mesa.'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="page-header-row">
      <button class="btn-back" @click="router.back()">← Voltar</button>
      <h2 class="page-title">
        Abrir Mesa{{ table ? ' ' + table.number : '' }}
      </h2>
    </div>

    <div v-if="loadingTable" class="loading">Carregando...</div>

    <template v-else>
      <div class="open-table-card">
        <p class="open-table-hint">
          Informe os dados do cliente para abrir a mesa e fazer o pedido.
        </p>

        <div v-if="error" class="error-msg">{{ error }}</div>

        <div class="form-group">
          <label class="form-label">Nome do cliente <span class="required">*</span></label>
          <input
            class="form-input"
            type="text"
            placeholder="Ex: João Silva"
            v-model="guestName"
            @keyup.enter="submit"
            autofocus
          />
        </div>

        <div class="form-group">
          <label class="form-label">Telefone <span class="optional">(opcional)</span></label>
          <input
            class="form-input"
            type="tel"
            placeholder="Ex: 91 99999-9999"
            v-model="guestPhone"
            @keyup.enter="submit"
          />
        </div>

        <button
          class="btn btn-primary btn-open"
          :disabled="loading || !guestName.trim()"
          @click="submit"
        >
          <span v-if="loading">Abrindo...</span>
          <span v-else>🍽 Abrir Mesa e Fazer Pedido</span>
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.page {
  padding: 16px 16px 80px;
  max-width: 480px;
  margin: 0 auto;
}

.page-header-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
}

.btn-back {
  background: none;
  border: none;
  color: var(--brand-accent);
  font-size: var(--fs-md);
  font-weight: var(--fw-semibold);
  cursor: pointer;
  padding: 0;
  font-family: inherit;
}

.page-title {
  font-size: var(--fs-xl);
  font-weight: var(--fw-bold);
  color: var(--text-primary);
  margin: 0;
}

.open-table-card {
  background: var(--surface);
  border: 1px solid var(--border-subtle);
  border-radius: var(--radius);
  padding: 20px;
  box-shadow: var(--shadow-sm);
}

.open-table-hint {
  font-size: var(--fs-sm);
  color: var(--text-muted);
  margin-bottom: 20px;
}

.form-group {
  margin-bottom: 16px;
}

.form-label {
  display: block;
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  color: var(--text-secondary);
  margin-bottom: 6px;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}

.required { color: var(--status-pending-color); }
.optional  { font-weight: 400; color: var(--text-muted); text-transform: none; }

.form-input {
  width: 100%;
  padding: 12px 14px;
  border: 1.5px solid var(--border-default);
  border-radius: var(--radius-sm);
  font-size: var(--fs-md);
  background: var(--surface);
  color: var(--text-primary);
  outline: none;
  font-family: inherit;
  transition: border-color var(--dur-fast);
  box-sizing: border-box;
}
.form-input:focus { border-color: var(--brand-accent); }

.btn-open {
  margin-top: 8px;
  padding: 14px;
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
}

.error-msg {
  background: var(--status-pending-bg);
  color: var(--status-pending-color);
  border: 1px solid var(--status-pending-border);
  padding: 10px 14px;
  border-radius: var(--radius-sm);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
  margin-bottom: 16px;
}
</style>
