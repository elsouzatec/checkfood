<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAccountStore } from '../stores/account'
import { maskPhone } from '../phone'

var router = useRouter()
var account = useAccountStore()

var name = ref(account.user?.name || '')
var savingName = ref(false)
var nameMsg = ref('')

var currentPassword = ref('')
var newPassword = ref('')
var savingPassword = ref(false)
var passwordMsg = ref('')
var passwordError = ref('')

var confirmingDelete = ref(false)
var deletePassword = ref('')
var deleting = ref(false)
var deleteError = ref('')

async function saveName() {
  if (name.value.trim().length < 2) return
  savingName.value = true
  nameMsg.value = ''
  try {
    await account.updateProfile({ name: name.value.trim() })
    nameMsg.value = 'Nome atualizado.'
  } catch (e) {
    nameMsg.value = 'Não foi possível salvar.'
  } finally {
    savingName.value = false
  }
}

async function savePassword() {
  passwordMsg.value = ''
  passwordError.value = ''
  if (newPassword.value.length < 8) { passwordError.value = 'A nova senha precisa de pelo menos 8 caracteres.'; return }

  savingPassword.value = true
  try {
    await account.updateProfile({ current_password: currentPassword.value, password: newPassword.value })
    currentPassword.value = ''
    newPassword.value = ''
    passwordMsg.value = 'Senha alterada. Outros aparelhos foram desconectados.'
  } catch (err) {
    passwordError.value = err.response?.data?.message || 'Não foi possível alterar a senha.'
  } finally {
    savingPassword.value = false
  }
}

async function deleteAccount() {
  deleteError.value = ''
  deleting.value = true
  try {
    await account.deleteAccount(deletePassword.value)
    router.replace('/')
  } catch (err) {
    deleteError.value = err.response?.data?.message || 'Não foi possível excluir a conta.'
  } finally {
    deleting.value = false
  }
}

async function logout() {
  await account.logout()
  router.replace('/')
}
</script>

<template>
  <div>
    <header class="acc-header">
      <button class="acc-header-back" aria-label="Voltar" @click="router.push('/minha-conta')">←</button>
      <div class="acc-header-text">
        <div class="acc-header-restaurant">{{ account.restaurant?.name }}</div>
        <div class="acc-header-title">Meus dados</div>
      </div>
    </header>

    <main class="acc-page">
      <section class="card profile-card">
        <div class="form-group">
          <label>Nome</label>
          <input v-model="name" type="text" autocomplete="name" />
        </div>
        <div class="form-group">
          <label>Telefone</label>
          <input :value="maskPhone(account.user?.phone)" type="tel" disabled />
          <span class="field-hint">O telefone identifica sua conta e não pode ser alterado.</span>
        </div>
        <div v-if="nameMsg" class="msg msg-success">{{ nameMsg }}</div>
        <button class="btn btn-primary" :disabled="savingName || name.trim().length < 2" @click="saveName">
          {{ savingName ? 'Salvando...' : 'Salvar nome' }}
        </button>
      </section>

      <div class="acc-section-title">Trocar senha</div>
      <section class="card profile-card">
        <div class="form-group">
          <label>Senha atual</label>
          <input v-model="currentPassword" type="password" autocomplete="current-password" />
        </div>
        <div class="form-group">
          <label>Nova senha</label>
          <input v-model="newPassword" type="password" placeholder="Mínimo de 8 caracteres" autocomplete="new-password" />
        </div>
        <div v-if="passwordError" class="msg msg-error">{{ passwordError }}</div>
        <div v-if="passwordMsg" class="msg msg-success">{{ passwordMsg }}</div>
        <button class="btn btn-secondary" :disabled="savingPassword || !currentPassword || !newPassword" @click="savePassword">
          {{ savingPassword ? 'Salvando...' : 'Alterar senha' }}
        </button>
      </section>

      <div class="acc-section-title">Excluir conta</div>
      <section class="card profile-card">
        <p class="danger-text">
          Remove seu nome, telefone e senha. Seus pedidos continuam no registro do restaurante,
          mas sem identificação. Não dá para desfazer.
        </p>
        <template v-if="confirmingDelete">
          <div class="form-group">
            <label>Confirme sua senha</label>
            <input v-model="deletePassword" type="password" autocomplete="current-password" />
          </div>
          <div v-if="deleteError" class="msg msg-error">{{ deleteError }}</div>
          <button class="btn btn-danger" :disabled="deleting || !deletePassword" @click="deleteAccount">
            {{ deleting ? 'Excluindo...' : 'Excluir minha conta' }}
          </button>
          <button class="entry-link" @click="confirmingDelete = false">Cancelar</button>
        </template>
        <button v-else class="btn btn-secondary" @click="confirmingDelete = true">Quero excluir minha conta</button>
      </section>

      <button class="entry-link" @click="logout">Sair da conta</button>
    </main>
  </div>
</template>

<style scoped>
.profile-card { padding: 16px; }
.profile-card input:disabled { background: var(--surface-sunken); color: var(--text-muted); }
.danger-text {
  font-size: var(--fs-sm);
  color: var(--text-secondary);
  line-height: 1.5;
  margin-bottom: 14px;
}
</style>
