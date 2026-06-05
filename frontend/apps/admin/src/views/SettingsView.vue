<script setup>
import { ref, onMounted } from 'vue'
import api from '../api'

const restaurant = ref(null)
const loading    = ref(true)
const saving     = ref(false)
const uploading  = ref(false)
const error      = ref('')
const success    = ref('')

const form = ref({
  name: '', address: '', phone: '',
  wifi_network: '', wifi_password: '', wifi_on_plaque: false,
  qr_phrase: '',
  show_total_during_service: true,
  show_order_progress: true,
  terminal_integration_enabled: false,
  terminal_acquirer: 'mercadopago',
  allow_client_payment: false,
  cashier_mode: false,
  tip_enabled: false,
  tip_default_percent: 10,
})

// ── Credenciais MP ── (formulário e flags separados do `form` principal,
// pois usam endpoint próprio e nunca devem ser enviados junto com PUT /admin/restaurant)
const mpForm = ref({
  mercadopago_access_token:   '',
  mercadopago_webhook_secret: '',
  mercadopago_public_key:     '',
})
const mpConfigured   = ref(false)
const mpWebhookReady = ref(false)
const mpSaving       = ref(false)
const mpError        = ref('')
const mpSuccess      = ref('')

async function fetchRestaurant() {
  try {
    const res = await api.get('/admin/restaurant')
    restaurant.value = res.data.restaurant
    const r = res.data.restaurant
    form.value = {
      name:                         r.name || '',
      address:                      r.address || '',
      phone:                        r.phone || '',
      wifi_network:                 r.wifi_network || '',
      wifi_password:                r.wifi_password || '',
      wifi_on_plaque:               !!r.wifi_on_plaque,
      qr_phrase:                    r.qr_phrase || '',
      show_total_during_service:    r.show_total_during_service !== false,
      show_order_progress:          r.show_order_progress !== false,
      terminal_integration_enabled: !!r.terminal_integration_enabled,
      terminal_acquirer:            r.terminal_acquirer || 'mercadopago',
      allow_client_payment:         !!r.allow_client_payment,
      cashier_mode:                 !!r.cashier_mode,
      tip_enabled:                  !!r.tip_enabled,
      tip_default_percent:          r.tip_default_percent ?? 10,
    }
    mpConfigured.value   = !!r.mercadopago_configured
    mpWebhookReady.value = !!r.mercadopago_webhook_ready
  } catch (err) {
    error.value = 'Erro ao carregar configurações.'
  } finally {
    loading.value = false
  }
}

async function saveMpCredentials() {
  mpSaving.value  = true
  mpError.value   = ''
  mpSuccess.value = ''
  try {
    // Só envia campos efetivamente preenchidos OU explicitamente esvaziados
    // pela UI; campos intocados (string vazia) ficam de fora do payload.
    const payload = {}
    for (const key of Object.keys(mpForm.value)) {
      const v = mpForm.value[key]
      if (v !== '') payload[key] = v
    }
    if (Object.keys(payload).length === 0) {
      mpError.value = 'Nada para salvar.'
      return
    }

    const res = await api.put('/admin/restaurant/payment-gateway', payload)
    mpConfigured.value   = !!res.data.mercadopago_configured
    mpWebhookReady.value = !!res.data.mercadopago_webhook_ready
    mpForm.value = {
      mercadopago_access_token:   '',
      mercadopago_webhook_secret: '',
      mercadopago_public_key:     '',
    }
    mpSuccess.value = 'Credenciais atualizadas.'
    setTimeout(() => { mpSuccess.value = '' }, 3000)
  } catch (err) {
    mpError.value = err.response?.data?.message || 'Erro ao salvar credenciais.'
  } finally {
    mpSaving.value = false
  }
}

function flashSuccess(msg) {
  success.value = msg
  setTimeout(() => { success.value = '' }, 3000)
}

async function save() {
  saving.value  = true
  error.value   = ''
  success.value = ''
  try {
    const res = await api.put('/admin/restaurant', JSON.parse(JSON.stringify(form.value)))
    restaurant.value = res.data.restaurant
    flashSuccess('Configurações salvas!')
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro ao salvar.'
  } finally {
    saving.value = false
  }
}

async function uploadLogo(event) {
  const file = event.target.files[0]
  if (!file) return
  uploading.value = true
  error.value     = ''
  const fd = new FormData()
  fd.append('logo', file)
  try {
    const res = await api.post('/admin/restaurant/logo', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    restaurant.value.logo_url = res.data.logo_url
    flashSuccess('Logo atualizada!')
  } catch (err) {
    error.value = err.response?.data?.message || 'Erro no upload.'
  } finally {
    uploading.value = false
  }
}

async function removeLogo() {
  if (!confirm('Remover logo?')) return
  try {
    await api.delete('/admin/restaurant/logo')
    restaurant.value.logo_url = null
  } catch (err) {
    alert('Erro ao remover.')
  }
}

function triggerUpload() {
  document.getElementById('logo-input').click()
}

onMounted(fetchRestaurant)
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1 class="page-title">Configurações</h1>
        <p class="page-subtitle">Dados do restaurante, plaquinhas, experiência do cliente e pagamento.</p>
      </div>
      <button class="btn btn-primary settings-save-top" :disabled="saving" @click="save">
        {{ saving ? 'Salvando...' : '💾 Salvar' }}
      </button>
    </div>

    <div v-if="loading" class="loading">Carregando...</div>

    <template v-else-if="restaurant">
      <div v-if="error"   class="error-msg">{{ error }}</div>
      <div v-if="success" class="success-msg">✓ {{ success }}</div>

      <!-- ── Logo ── -->
      <div class="card">
        <div class="card-section">
          <h3 class="section-title">Logo do Restaurante</h3>
          <p class="section-desc">Aparece na plaquinha de QR Code das mesas.</p>

          <div class="image-upload">
            <div class="image-upload-preview" @click="triggerUpload">
              <img v-if="restaurant.logo_url" :src="restaurant.logo_url" alt="Logo" />
              <div v-else class="image-upload-placeholder">
                <span class="icon">📷</span>
                <small>Clique para enviar</small>
              </div>
              <div class="image-upload-overlay">
                {{ uploading ? 'Enviando...' : 'Alterar' }}
              </div>
            </div>

            <div class="image-upload-actions">
              <button
                class="btn btn-secondary btn-sm"
                :disabled="uploading"
                @click="triggerUpload"
              >
                {{ uploading ? 'Enviando...' : '⬆ Enviar logo' }}
              </button>
              <button
                v-if="restaurant.logo_url"
                class="btn btn-danger btn-sm"
                @click="removeLogo"
              >
                Remover
              </button>
            </div>
          </div>

          <input
            id="logo-input"
            type="file"
            accept="image/jpeg,image/png,image/webp,image/svg+xml"
            class="hidden-file-input"
            @change="uploadLogo"
          />
          <p class="hint">Formatos: JPG, PNG, WebP, SVG. Tamanho máximo: 2MB.</p>
        </div>
      </div>

      <!-- ── Dados ── -->
      <div class="card">
        <div class="card-section">
          <h3 class="section-title">Dados do Restaurante</h3>

          <div class="form-group">
            <label>Nome</label>
            <input v-model="form.name" placeholder="Nome do restaurante" />
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Endereço <span class="field-hint">(opcional)</span></label>
              <input v-model="form.address" placeholder="Rua, número, bairro" />
            </div>
            <div class="form-group">
              <label>Telefone <span class="field-hint">(opcional)</span></label>
              <input v-model="form.phone" type="tel" placeholder="(91) 99999-9999" />
            </div>
          </div>
        </div>
      </div>

      <!-- ── Plaquinha QR ── -->
      <div class="card">
        <div class="card-section">
          <h3 class="section-title">Plaquinha QR Code</h3>
          <p class="section-desc">Informações impressas na plaquinha de cada mesa.</p>

          <div class="form-group">
            <label>Frase da plaquinha</label>
            <input v-model="form.qr_phrase" placeholder="Ex: Escaneie e faça seu pedido!" />
            <p class="hint">Se vazio, usa: "Escaneie e faça seu pedido!"</p>
          </div>

          <div class="form-row">
            <div class="form-group">
              <label>Wi-Fi — Nome da rede</label>
              <input v-model="form.wifi_network" placeholder="Ex: Restaurante_WiFi" />
            </div>
            <div class="form-group">
              <label>Wi-Fi — Senha</label>
              <input v-model="form.wifi_password" placeholder="Ex: senha123" />
            </div>
          </div>

          <div class="form-checkbox-stacked">
            <label class="form-checkbox">
              <input type="checkbox" v-model="form.wifi_on_plaque" />
              Exibir Wi-Fi na plaquinha da mesa
            </label>
          </div>
        </div>
      </div>

      <!-- ── Experiência do Cliente ── -->
      <div class="card">
        <div class="card-section">
          <h3 class="section-title">Experiência do Cliente</h3>
          <p class="section-desc">Configurações que afetam o app do cliente.</p>

          <div class="form-checkbox-stacked">
            <label class="form-checkbox">
              <input type="checkbox" v-model="form.show_total_during_service" />
              Exibir total de consumo durante o atendimento
            </label>
            <p class="hint">Quando desativado, o cliente só vê o total no fechamento da conta.</p>
          </div>

          <div class="form-checkbox-stacked settings-stack-gap">
            <label class="form-checkbox">
              <input type="checkbox" v-model="form.show_order_progress" />
              Exibir barra de progresso dos pedidos
            </label>
            <p class="hint">
              Mostra ao cliente o status de cada pedido
              (Pedindo → Na fila → Preparando → Pronto).
            </p>
          </div>
        </div>
      </div>

      <!-- ── Pagamento ── -->
      <div class="card">
        <div class="card-section">
          <h3 class="section-title">💳 Pagamento</h3>
          <p class="section-desc">Controle como os pagamentos podem ser realizados.</p>

          <div class="form-checkbox-stacked">
            <label class="form-checkbox">
              <input type="checkbox" v-model="form.allow_client_payment" />
              Permitir cliente pagar no próprio dispositivo
            </label>
            <p class="hint">
              Quando ativo, o garçom pode optar por deixar o cliente efetuar o
              pagamento diretamente pelo app, sem precisar passar o dispositivo.
            </p>
          </div>

          <div class="form-checkbox-stacked settings-stack-gap">
            <label class="form-checkbox">
              <input type="checkbox" v-model="form.cashier_mode" />
              Modo Caixa — encaminhar mesas para o caixa físico
            </label>
            <p class="hint">
              Quando ativo, o garçom não processa o pagamento: seleciona o meio
              e encaminha a mesa ao app do caixa, que confirma o recebimento.
              A mesa é liberada imediatamente após o encaminhamento.
            </p>
          </div>
        </div>
      </div>

      <!-- ── Taxa de Serviço ── -->
      <div class="card">
        <div class="card-section">
          <h3 class="section-title">🪙 Taxa de Serviço</h3>
          <p class="section-desc">
            Quando habilitada, o garçom verá um campo editável de taxa ao fechar a conta.
            O percentual pode ser ajustado ou zerado no momento do fechamento.
          </p>

          <div class="form-checkbox-stacked">
            <label class="form-checkbox">
              <input type="checkbox" v-model="form.tip_enabled" />
              Habilitar taxa de serviço
            </label>
            <p class="hint">Aparece no card de total do app do garçom ao fechar conta.</p>
          </div>

          <div v-if="form.tip_enabled" class="form-group settings-stack-gap">
            <label>Percentual padrão</label>
            <div class="tip-percent-wrap">
              <input
                class="tip-percent-input"
                type="number"
                min="0"
                max="100"
                step="1"
                v-model="form.tip_default_percent"
              />
              <span class="tip-percent-symbol">%</span>
            </div>
            <p class="hint">O garçom pode alterar este valor no momento do fechamento da conta.</p>
          </div>
        </div>
      </div>

      <!-- ── Maquininha (Terminal POS) ── -->
      <div class="card">
        <div class="card-section">
          <h3 class="section-title">🖥️ Maquininha (Terminal POS)</h3>
          <p class="section-desc">
            Integração com terminal de pagamento físico (Sunmi P2). Quando ativo,
            o garçom pode acionar a maquininha direto pelo sistema.
          </p>

          <div class="form-checkbox-stacked">
            <label class="form-checkbox">
              <input type="checkbox" v-model="form.terminal_integration_enabled" />
              Maquininha integrada
            </label>
            <p class="hint">Exibe o botão "Maquininha" no modal de pagamento do garçom.</p>
          </div>

          <div v-if="form.terminal_integration_enabled" class="form-group settings-stack-gap">
            <label>Adquirente</label>
            <select v-model="form.terminal_acquirer">
              <option value="mercadopago">Mercado Pago (Point Smart)</option>
              <option value="pagseguro" disabled>PagSeguro PlugPag (em breve)</option>
              <option value="stone"     disabled>Stone Smart POS (em breve)</option>
            </select>
            <p class="hint">Certifique-se que o app da adquirente está instalado na Sunmi P2.</p>
          </div>
        </div>
      </div>

      <!-- ── Credenciais Mercado Pago ── -->
      <div class="card">
        <div class="card-section">
          <h3 class="section-title">🔑 Credenciais Mercado Pago</h3>
          <p class="section-desc">
            Configure sua conta para que pagamentos Pix caiam diretamente
            no seu Mercado Pago. As credenciais são armazenadas criptografadas
            e nunca são exibidas após salvas.
          </p>

          <div class="mp-status">
            <span :class="['mp-status-pill', mpConfigured ? 'on' : 'off']">
              Access Token: {{ mpConfigured ? 'configurado' : 'não configurado' }}
            </span>
            <span :class="['mp-status-pill', mpWebhookReady ? 'on' : 'off']">
              Webhook Secret: {{ mpWebhookReady ? 'configurado' : 'não configurado' }}
            </span>
          </div>

          <div v-if="mpError"   class="error-msg">{{ mpError }}</div>
          <div v-if="mpSuccess" class="success-msg">✓ {{ mpSuccess }}</div>

          <div class="form-group settings-stack-gap">
            <label>Access Token</label>
            <input
              v-model="mpForm.mercadopago_access_token"
              type="password"
              autocomplete="off"
              placeholder="APP_USR-…"
            />
            <p class="hint">Token de produção da sua aplicação no Mercado Pago.</p>
          </div>

          <div class="form-group">
            <label>Webhook Secret</label>
            <input
              v-model="mpForm.mercadopago_webhook_secret"
              type="password"
              autocomplete="off"
              placeholder="chave secreta do webhook"
            />
            <p class="hint">
              Obtenha em Mercado Pago → Notificações → Webhooks. Sem ele,
              confirmações automáticas de Pix não são validadas.
            </p>
          </div>

          <div class="form-group">
            <label>Public Key <span class="field-hint">(opcional)</span></label>
            <input
              v-model="mpForm.mercadopago_public_key"
              type="text"
              autocomplete="off"
              placeholder="APP_USR-…-pubkey"
            />
            <p class="hint">Necessária apenas para integrações de checkout web.</p>
          </div>

          <button
            class="btn btn-primary"
            :disabled="mpSaving"
            @click="saveMpCredentials"
          >
            {{ mpSaving ? 'Salvando...' : '💾 Salvar credenciais' }}
          </button>
        </div>
      </div>

      <!-- Botão final redundante para rolagens longas -->
      <div class="settings-footer">
        <button class="btn btn-primary settings-save-bottom" :disabled="saving" @click="save">
          {{ saving ? 'Salvando...' : '💾 Salvar Configurações' }}
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.settings-save-top {
  align-self: flex-start;
}

.tip-percent-wrap {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  border: 1.5px solid var(--admin-border);
  border-radius: var(--radius-sm);
  padding: 6px 10px;
  background: var(--admin-bg-secondary);
}
.tip-percent-input {
  width: 56px;
  border: none;
  background: transparent;
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  color: var(--admin-text-primary);
  font-family: inherit;
  text-align: right;
  outline: none;
  -moz-appearance: textfield;
}
.tip-percent-input::-webkit-outer-spin-button,
.tip-percent-input::-webkit-inner-spin-button { -webkit-appearance: none; }
.tip-percent-symbol {
  font-size: var(--fs-md);
  font-weight: var(--fw-bold);
  color: var(--accent);
}

.hidden-file-input { display: none; }

/* Espaçamento entre grupos de checkbox stacked dentro da mesma seção */
.settings-stack-gap { margin-top: 16px; }

.settings-footer {
  display: flex;
  justify-content: flex-end;
  margin-top: 8px;
  padding-top: 8px;
}
.settings-save-bottom {
  min-width: 220px;
}

/* Espaçamento entre cards — cada .card recebe margin-bottom do global,
   aqui só ajustamos ritmo vertical geral da página. */
.card { margin-bottom: 18px; }
.card:last-of-type { margin-bottom: 8px; }

/* ── Status MP ── */
.mp-status {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.mp-status-pill {
  font-size: 12px;
  padding: 4px 10px;
  border-radius: 999px;
  font-weight: var(--fw-bold, 700);
}
.mp-status-pill.on  { background: #dcfce7; color: #166534; }
.mp-status-pill.off { background: #fee2e2; color: #991b1b; }
</style>
