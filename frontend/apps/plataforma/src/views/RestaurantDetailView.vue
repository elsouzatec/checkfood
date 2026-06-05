<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const props = defineProps({ id: { type: [String, Number], required: true } })
const router = useRouter()

const loading = ref(true)
const error = ref('')
const notice = ref('')

const restaurant = ref(null)
const managers = ref([])
const subscription = ref(null)
const payments = ref([])

const edit = reactive({ name: '', slug: '', address: '', phone: '', deployment_mode: 'domain', frontend_url: '' })
const bill = reactive({ monthly_amount: '', billing_day: '', notes: '' })
const pay = reactive({ amount: '', method: 'pix', note: '' })
const newManager = reactive({ name: '', phone: '', email: '', password: '' })

const savingEdit = ref(false)
const savingBill = ref(false)
const savingPay = ref(false)
const savingMgr = ref(false)
const togglingActive = ref(false)

function brl(v) {
  return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const [rRes, bRes] = await Promise.all([
      api.get(`/platform/restaurants/${props.id}`),
      api.get(`/platform/restaurants/${props.id}/billing`),
    ])
    restaurant.value = rRes.data.restaurant
    managers.value = rRes.data.managers
    Object.assign(edit, {
      name: restaurant.value.name,
      slug: restaurant.value.slug,
      address: restaurant.value.address || '',
      phone: restaurant.value.phone || '',
      deployment_mode: restaurant.value.deployment_mode || 'domain',
      frontend_url: restaurant.value.frontend_url || '',
    })
    subscription.value = bRes.data.subscription
    payments.value = bRes.data.payments
    if (subscription.value) {
      bill.monthly_amount = subscription.value.monthly_amount
      bill.billing_day = subscription.value.billing_day || ''
      bill.notes = subscription.value.notes || ''
    }
  } catch (e) {
    error.value = e.response?.data?.message || 'Erro ao carregar restaurante.'
  } finally {
    loading.value = false
  }
}

function flash(msg) { notice.value = msg; setTimeout(() => (notice.value = ''), 3000) }
function showErr(e, fallback) {
  const errs = e.response?.data?.errors
  error.value = errs ? Object.values(errs).flat().join(' ') : (e.response?.data?.message || fallback)
  setTimeout(() => (error.value = ''), 4000)
}

async function saveEdit() {
  savingEdit.value = true
  try {
    const payload = { ...edit }
    if (!payload.frontend_url) payload.frontend_url = null
    const { data } = await api.put(`/platform/restaurants/${props.id}`, payload)
    restaurant.value = data.restaurant
    flash('Restaurante atualizado.')
  } catch (e) { showErr(e, 'Erro ao salvar.') } finally { savingEdit.value = false }
}

async function toggleActive() {
  togglingActive.value = true
  try {
    const { data } = await api.patch(`/platform/restaurants/${props.id}/toggle-active`)
    restaurant.value.is_active = data.is_active
    flash(data.message)
  } catch (e) { showErr(e, 'Erro ao alterar status.') } finally { togglingActive.value = false }
}

async function saveBilling() {
  savingBill.value = true
  try {
    const { data } = await api.put(`/platform/restaurants/${props.id}/billing`, {
      monthly_amount: Number(bill.monthly_amount || 0),
      billing_day: bill.billing_day ? Number(bill.billing_day) : null,
      notes: bill.notes || null,
    })
    subscription.value = data.subscription
    flash('Mensalidade configurada.')
  } catch (e) { showErr(e, 'Erro ao configurar mensalidade.') } finally { savingBill.value = false }
}

async function registerPayment() {
  savingPay.value = true
  try {
    const { data } = await api.post(`/platform/restaurants/${props.id}/billing/pay`, {
      amount: pay.amount ? Number(pay.amount) : null,
      method: pay.method,
      note: pay.note || null,
    })
    subscription.value = data.subscription
    payments.value.unshift(data.payment)
    if (restaurant.value) restaurant.value.is_active = true
    pay.amount = ''; pay.note = ''
    flash('Pagamento registrado.')
  } catch (e) { showErr(e, 'Erro ao registrar pagamento.') } finally { savingPay.value = false }
}

async function addManager() {
  if (!newManager.name || !newManager.phone || !newManager.password) {
    showErr({}, 'Preencha nome, telefone e senha do gerente.')
    return
  }
  savingMgr.value = true
  try {
    const { data } = await api.post(`/platform/restaurants/${props.id}/managers`, { ...newManager })
    managers.value.push({ ...data.manager, is_active: true })
    Object.assign(newManager, { name: '', phone: '', email: '', password: '' })
    flash('Gerente adicionado.')
  } catch (e) { showErr(e, 'Erro ao adicionar gerente.') } finally { savingMgr.value = false }
}

const statusPill = computed(() => {
  if (!subscription.value) return null
  const s = subscription.value
  if (s.status === 'suspended') return { cls: 'red', text: 'Suspenso' }
  if (s.is_overdue) return { cls: 'amber', text: 'Vencido' }
  if (s.status === 'active') return { cls: 'green', text: 'Em dia' }
  return { cls: 'amber', text: 'Em avaliação' }
})

onMounted(load)
</script>

<template>
  <router-link :to="{ name: 'restaurants' }" class="link-back">← Restaurantes</router-link>

  <div v-if="error" class="alert error">{{ error }}</div>
  <div v-if="notice" class="alert success">{{ notice }}</div>
  <div v-if="loading" class="muted">Carregando…</div>

  <template v-else-if="restaurant">
    <div class="page-header">
      <div>
        <div class="page-title">{{ restaurant.name }}</div>
        <div class="page-subtitle">
          {{ restaurant.slug }} ·
          <span v-if="restaurant.is_active" class="pill green">Ativo</span>
          <span v-else class="pill red">Suspenso</span>
        </div>
      </div>
      <button
        class="btn"
        :class="restaurant.is_active ? 'btn-danger' : 'btn-primary'"
        :disabled="togglingActive"
        @click="toggleActive"
      >
        {{ restaurant.is_active ? 'Suspender' : 'Reativar' }}
      </button>
    </div>

    <!-- Dados + acesso -->
    <div class="card">
      <div class="card-title">Dados e acesso</div>
      <div class="form-grid">
        <div class="field"><label>Nome</label><input v-model="edit.name" /></div>
        <div class="field"><label>Slug</label><input v-model="edit.slug" /></div>
        <div class="field"><label>Endereço</label><input v-model="edit.address" /></div>
        <div class="field"><label>Telefone</label><input v-model="edit.phone" /></div>
        <div class="field">
          <label>Modo de operação</label>
          <select v-model="edit.deployment_mode">
            <option value="domain">Domínio público</option>
            <option value="intranet">Intranet (rede local)</option>
          </select>
        </div>
        <div class="field">
          <label>URL base (frontend)</label>
          <input v-model="edit.frontend_url" :placeholder="edit.deployment_mode === 'intranet' ? 'http://192.168.0.50:8080' : 'https://meurestaurante.com'" />
          <span class="hint">Base dos QR codes. Em branco usa o padrão da plataforma.</span>
        </div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" :disabled="savingEdit" @click="saveEdit">{{ savingEdit ? 'Salvando…' : 'Salvar alterações' }}</button>
      </div>
    </div>

    <!-- Mensalidade -->
    <div class="card">
      <div class="card-title">
        Mensalidade
        <span v-if="statusPill" class="pill" :class="statusPill.cls" style="margin-left:8px">{{ statusPill.text }}</span>
      </div>
      <p v-if="subscription" class="muted" style="margin-bottom:14px">
        Pago até: <strong>{{ subscription.paid_until || '—' }}</strong>
      </p>

      <div class="form-grid">
        <div class="field">
          <label>Valor mensal (R$)</label>
          <input v-model="bill.monthly_amount" type="number" step="0.01" min="0" />
        </div>
        <div class="field">
          <label>Dia de vencimento</label>
          <input v-model="bill.billing_day" type="number" min="1" max="28" placeholder="1–28" />
        </div>
        <div class="field">
          <label>Observações</label>
          <input v-model="bill.notes" />
        </div>
      </div>
      <div class="form-actions">
        <button class="btn" :disabled="savingBill" @click="saveBilling">{{ savingBill ? 'Salvando…' : 'Salvar mensalidade' }}</button>
      </div>

      <hr style="border:none; border-top:1px solid var(--admin-border); margin:20px 0" />

      <div class="card-title" style="font-size:14px">Registrar pagamento</div>
      <div class="form-grid">
        <div class="field">
          <label>Valor (em branco = mensalidade)</label>
          <input v-model="pay.amount" type="number" step="0.01" min="0" />
        </div>
        <div class="field">
          <label>Método</label>
          <select v-model="pay.method">
            <option value="pix">PIX</option>
            <option value="dinheiro">Dinheiro</option>
            <option value="transferencia">Transferência</option>
            <option value="manual">Outro</option>
          </select>
        </div>
        <div class="field"><label>Observação</label><input v-model="pay.note" /></div>
      </div>
      <div class="form-actions">
        <button class="btn btn-primary" :disabled="savingPay" @click="registerPayment">{{ savingPay ? 'Registrando…' : '+ Registrar pagamento' }}</button>
      </div>

      <table v-if="payments.length" class="data-table" style="margin-top:18px">
        <thead><tr><th>Pago em</th><th>Valor</th><th>Período</th><th>Método</th></tr></thead>
        <tbody>
          <tr v-for="p in payments" :key="p.id">
            <td>{{ p.paid_at }}</td>
            <td>{{ brl(p.amount) }}</td>
            <td class="muted">{{ p.period_start }} → {{ p.period_end }}</td>
            <td>{{ p.method }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Gerentes -->
    <div class="card">
      <div class="card-title">Gerentes</div>
      <table v-if="managers.length" class="data-table" style="margin-bottom:18px">
        <thead><tr><th>Nome</th><th>Telefone</th><th>E-mail</th><th>Ativo</th></tr></thead>
        <tbody>
          <tr v-for="m in managers" :key="m.id">
            <td>{{ m.name }}</td>
            <td>{{ m.phone }}</td>
            <td class="muted">{{ m.email || '—' }}</td>
            <td><span class="pill" :class="m.is_active ? 'green' : 'red'">{{ m.is_active ? 'Sim' : 'Não' }}</span></td>
          </tr>
        </tbody>
      </table>

      <div class="card-title" style="font-size:14px">Adicionar gerente</div>
      <div class="form-grid">
        <div class="field"><label>Nome</label><input v-model="newManager.name" /></div>
        <div class="field"><label>Telefone</label><input v-model="newManager.phone" /></div>
        <div class="field"><label>E-mail</label><input v-model="newManager.email" type="email" /></div>
        <div class="field"><label>Senha</label><input v-model="newManager.password" type="text" placeholder="mín. 6 caracteres" /></div>
      </div>
      <div class="form-actions">
        <button class="btn" :disabled="savingMgr" @click="addManager">{{ savingMgr ? 'Adicionando…' : '+ Adicionar gerente' }}</button>
      </div>
    </div>
  </template>
</template>
