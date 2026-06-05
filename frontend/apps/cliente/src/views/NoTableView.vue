<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'

var router = useRouter()
var showScanner = ref(false)
var scanError = ref('')

function openScanner() {
  // Verificar se tem suporte a camera
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    scanError.value = 'Seu navegador não suporta acesso à câmera. Escaneie o QR Code com o app de câmera do seu celular.'
    return
  }
  showScanner.value = true
  scanError.value = ''
  startCamera()
}

var videoEl = ref(null)
var canvasEl = ref(null)
var stream = ref(null)
var scanning = ref(false)

async function startCamera() {
  try {
    var mediaStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment' }
    })
    stream.value = mediaStream
    scanning.value = true

    // Aguardar o DOM atualizar com o video element
    setTimeout(function() {
      var video = document.getElementById('qr-video')
      if (video) {
        video.srcObject = mediaStream
        video.play()
        requestAnimationFrame(scanFrame)
      }
    }, 100)
  } catch (err) {
    scanError.value = 'Não foi possível acessar a câmera. Verifique as permissões.'
    showScanner.value = false
  }
}

function scanFrame() {
  if (!scanning.value) return

  var video = document.getElementById('qr-video')
  var canvas = document.getElementById('qr-canvas')
  if (!video || !canvas || video.readyState !== video.HAVE_ENOUGH_DATA) {
    requestAnimationFrame(scanFrame)
    return
  }

  var ctx = canvas.getContext('2d')
  canvas.width = video.videoWidth
  canvas.height = video.videoHeight
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height)

  try {
    var imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
    // Tentar usar BarcodeDetector (Chrome/Edge mobile)
    if (window.BarcodeDetector) {
      var detector = new BarcodeDetector({ formats: ['qr_code'] })
      detector.detect(canvas).then(function(barcodes) {
        if (barcodes.length > 0) {
          handleQrResult(barcodes[0].rawValue)
          return
        }
        requestAnimationFrame(scanFrame)
      }).catch(function() {
        requestAnimationFrame(scanFrame)
      })
    } else {
      // Fallback: sem BarcodeDetector, instruir o usuario
      scanError.value = 'Scanner nativo não disponível. Use o app de câmera do seu celular para escanear o QR Code da mesa.'
      stopCamera()
    }
  } catch (e) {
    requestAnimationFrame(scanFrame)
  }
}

function handleQrResult(url) {
  stopCamera()

  // Extrair o token da URL: /mesa/{token}
  var match = url.match(/\/mesa\/([a-f0-9-]+)/i)
  if (match && match[1]) {
    router.push('/mesa/' + match[1])
  } else {
    // Tentar navegar direto se for URL completa do PagFood
    try {
      var parsed = new URL(url)
      var path = parsed.pathname
      if (path.indexOf('/mesa/') === 0) {
        router.push(path)
      } else {
        scanError.value = 'QR Code não reconhecido. Escaneie o código da sua mesa.'
        showScanner.value = false
      }
    } catch (e) {
      scanError.value = 'QR Code não reconhecido.'
      showScanner.value = false
    }
  }
}

function stopCamera() {
  scanning.value = false
  if (stream.value) {
    stream.value.getTracks().forEach(function(track) { track.stop() })
    stream.value = null
  }
  showScanner.value = false
}

function goToAccount() {
  router.push('/conta')
}
</script>

<template>
  <div class="landing-page">
    <!-- Header -->
    <div class="landing-header">
      <div class="logo-mark">🍽</div>
      <h1 class="logo-text">PagFood</h1>
      <p class="logo-sub">Cardápio Digital</p>
    </div>

    <!-- Error -->
    <div v-if="scanError" class="scan-error">
      {{ scanError }}
      <button class="scan-error-close" @click="scanError = ''">✕</button>
    </div>

    <!-- Options -->
    <div class="options-area">
      <div class="option-card option-scan" @click="openScanner">
        <div class="option-icon">📱</div>
        <div class="option-content">
          <div class="option-title">Escanear QR Code</div>
          <div class="option-desc">Aponte a câmera para o código na sua mesa</div>
        </div>
        <div class="option-arrow">→</div>
      </div>

      <div class="option-divider">
        <span>ou</span>
      </div>

      <div class="option-card option-account" @click="goToAccount">
        <div class="option-icon">👤</div>
        <div class="option-content">
          <div class="option-title">Acessar Conta</div>
          <div class="option-desc">Para clientes cadastrados</div>
        </div>
        <div class="option-arrow">→</div>
      </div>
    </div>

    <!-- Footer hint -->
    <div class="landing-footer">
      <p>Primeira vez? Basta escanear o QR Code da mesa para começar a pedir!</p>
    </div>

    <!-- Camera Scanner Overlay -->
    <div v-if="showScanner" class="scanner-overlay">
      <div class="scanner-header">
        <button class="scanner-close" @click="stopCamera">✕ Fechar</button>
      </div>
      <div class="scanner-viewport">
        <video id="qr-video" playsinline autoplay muted class="scanner-video"></video>
        <canvas id="qr-canvas" style="display:none"></canvas>
        <div class="scanner-frame">
          <div class="scanner-corner tl"></div>
          <div class="scanner-corner tr"></div>
          <div class="scanner-corner bl"></div>
          <div class="scanner-corner br"></div>
        </div>
        <div class="scanner-hint">Posicione o QR Code dentro da área</div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.landing-page {
  min-height: 100vh;
  background: linear-gradient(180deg, #fff 0%, #f5f5f7 100%);
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 0 20px;
}

/* ── Header ── */
.landing-header {
  text-align: center;
  padding: 60px 0 32px;
}
.logo-mark {
  font-size: 48px;
  margin-bottom: 8px;
}
.logo-text {
  font-size: 32px;
  font-weight: 800;
  color: #e63946;
  letter-spacing: -1px;
  margin: 0;
}
.logo-sub {
  font-size: 13px;
  color: #aaa;
  text-transform: uppercase;
  letter-spacing: 2px;
  margin-top: 4px;
}

/* ── Error ── */
.scan-error {
  background: rgba(230,57,70,0.08);
  border: 1px solid rgba(230,57,70,0.2);
  color: #e63946;
  padding: 12px 16px;
  border-radius: 12px;
  font-size: 13px;
  line-height: 1.4;
  max-width: 400px;
  width: 100%;
  margin-bottom: 16px;
  display: flex;
  align-items: flex-start;
  gap: 10px;
}
.scan-error-close {
  background: none;
  border: none;
  color: #e63946;
  font-size: 16px;
  cursor: pointer;
  flex-shrink: 0;
  padding: 0;
}

/* ── Options ── */
.options-area {
  width: 100%;
  max-width: 400px;
}
.option-card {
  display: flex;
  align-items: center;
  gap: 14px;
  background: #fff;
  border-radius: 16px;
  padding: 20px;
  cursor: pointer;
  transition: all 0.2s;
  box-shadow: 0 2px 12px rgba(0,0,0,0.06);
  border: 2px solid transparent;
  touch-action: manipulation;
}
.option-card:active {
  transform: scale(0.98);
}
.option-scan:hover, .option-scan:active {
  border-color: #e63946;
  box-shadow: 0 4px 20px rgba(230,57,70,0.15);
}
.option-account:hover, .option-account:active {
  border-color: #3498db;
  box-shadow: 0 4px 20px rgba(52,152,219,0.15);
}
.option-icon {
  font-size: 32px;
  flex-shrink: 0;
  width: 52px;
  height: 52px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 14px;
}
.option-scan .option-icon {
  background: rgba(230,57,70,0.08);
}
.option-account .option-icon {
  background: rgba(52,152,219,0.08);
}
.option-content {
  flex: 1;
  min-width: 0;
}
.option-title {
  font-size: 16px;
  font-weight: 700;
  color: #1a1a2e;
  margin-bottom: 3px;
}
.option-desc {
  font-size: 12px;
  color: #999;
}
.option-arrow {
  font-size: 18px;
  color: #ccc;
  flex-shrink: 0;
}
.option-scan:hover .option-arrow { color: #e63946; }
.option-account:hover .option-arrow { color: #3498db; }

/* ── Divider ── */
.option-divider {
  display: flex;
  align-items: center;
  gap: 16px;
  margin: 16px 0;
}
.option-divider::before,
.option-divider::after {
  content: '';
  flex: 1;
  height: 1px;
  background: #e0e0e0;
}
.option-divider span {
  font-size: 12px;
  color: #bbb;
  text-transform: uppercase;
  letter-spacing: 1px;
}

/* ── Footer ── */
.landing-footer {
  margin-top: auto;
  padding: 32px 0;
  text-align: center;
}
.landing-footer p {
  font-size: 12px;
  color: #bbb;
  max-width: 280px;
  line-height: 1.5;
}

/* ── Scanner Overlay ── */
.scanner-overlay {
  position: fixed;
  inset: 0;
  background: #000;
  z-index: 200;
  display: flex;
  flex-direction: column;
}
.scanner-header {
  padding: 16px 20px;
  display: flex;
  justify-content: flex-end;
  z-index: 10;
}
.scanner-close {
  background: rgba(255,255,255,0.15);
  border: none;
  color: #fff;
  padding: 8px 18px;
  border-radius: 20px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  font-family: inherit;
  touch-action: manipulation;
}
.scanner-viewport {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
}
.scanner-video {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.scanner-frame {
  position: absolute;
  width: 240px;
  height: 240px;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
}
.scanner-corner {
  position: absolute;
  width: 32px;
  height: 32px;
  border-color: #e63946;
  border-style: solid;
  border-width: 0;
}
.tl { top: 0; left: 0; border-top-width: 4px; border-left-width: 4px; border-radius: 8px 0 0 0; }
.tr { top: 0; right: 0; border-top-width: 4px; border-right-width: 4px; border-radius: 0 8px 0 0; }
.bl { bottom: 0; left: 0; border-bottom-width: 4px; border-left-width: 4px; border-radius: 0 0 0 8px; }
.br { bottom: 0; right: 0; border-bottom-width: 4px; border-right-width: 4px; border-radius: 0 0 8px 0; }
.scanner-hint {
  position: absolute;
  bottom: 80px;
  left: 50%;
  transform: translateX(-50%);
  color: rgba(255,255,255,0.8);
  font-size: 14px;
  font-weight: 500;
  text-align: center;
  white-space: nowrap;
}
</style>
