<script setup>
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import api from '../api'

const router = useRouter()
const saving = ref(false)
const error = ref('')

const form = reactive({
  name: '',
  slug: '',
  address: '',
  phone: '',
  deployment_mode: 'domain',
  frontend_url: '',
  manager_name: '',
  manager_phone: '',
  manager_email: '',
  manager_password: '',
})

function slugify() {
  if (!form.slug && form.name) {
    form.slug = form.name.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
      .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
  }
}

async function submit() {
  error.value = ''
  if (!form.name || !form.slug || !form.manager_name || !form.manager_phone || !form.manager_password) {
    error.value = 'Preencha os campos obrigatórios.'
    return
  }
  saving.value = true
  try {
    const payload = { ...form }
    if (!payload.frontend_url) delete payload.frontend_url
    const { data } = await api.post('/platform/restaurants', payload)
    router.push({ name: 'restaurant', params: { id: data.restaurant.id } })
  } catch (e) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(' ') : (e.response?.data?.message || 'Erro ao criar restaurante.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <router-link :to="{ name: 'restaurants' }" class="link-back">← Restaurantes</router-link>
  <div class="page-header">
    <div class="page-title">Novo restaurante</div>
  </div>

  <div v-if="error" class="alert error">{{ error }}</div>

  <form @submit.prevent="submit">
    <div class="card">
      <div class="card-title">Dados do restaurante</div>
      <div class="form-grid">
        <div class="field">
          <label>Nome *</label>
          <input v-model="form.name" @blur="slugify" placeholder="Ex.: Restaurante do João" />
        </div>
        <div class="field">
          <label>Slug (código) *</label>
          <input v-model="form.slug" placeholder="restaurante-do-joao" />
          <span class="hint">Usado no login dos funcionários.</span>
        </div>
        <div class="field">
          <label>Endereço</label>
          <input v-model="form.address" />
        </div>
        <div class="field">
          <label>Telefone</label>
          <input v-model="form.phone" />
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">Acesso</div>
      <div class="form-grid">
        <div class="field">
          <label>Modo de operação</label>
          <select v-model="form.deployment_mode">
            <option value="domain">Domínio público</option>
            <option value="intranet">Intranet (rede local)</option>
          </select>
        </div>
        <div class="field">
          <label>URL base (frontend)</label>
          <input v-model="form.frontend_url" :placeholder="form.deployment_mode === 'intranet' ? 'http://192.168.0.50:8080' : 'https://meurestaurante.com'" />
          <span class="hint">Usada para gerar os QR codes das mesas. Em branco usa o padrão da plataforma.</span>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-title">Gerente inicial</div>
      <div class="form-grid">
        <div class="field">
          <label>Nome *</label>
          <input v-model="form.manager_name" />
        </div>
        <div class="field">
          <label>Telefone *</label>
          <input v-model="form.manager_phone" />
        </div>
        <div class="field">
          <label>E-mail</label>
          <input v-model="form.manager_email" type="email" />
        </div>
        <div class="field">
          <label>Senha *</label>
          <input v-model="form.manager_password" type="text" placeholder="mín. 6 caracteres" />
        </div>
      </div>
    </div>

    <div class="form-actions">
      <button type="submit" class="btn btn-primary" :disabled="saving">{{ saving ? 'Criando…' : 'Criar restaurante' }}</button>
      <button type="button" class="btn" @click="router.push({ name: 'restaurants' })">Cancelar</button>
    </div>
  </form>
</template>
