<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { parseScannedUrl } from '../links'

// Leitor de QR em tela cheia. Usa o BarcodeDetector nativo (Chrome/Android)
// e cai no jsQR quando ele não existe (Safari/iPhone).
// Emite `result` com { type: 'table'|'restaurant', ... } (ver links.js).
var emit = defineEmits(['result', 'close', 'error'])

var videoEl = ref(null)
var canvasEl = ref(null)
var hint = ref('Posicione o QR Code dentro da área')

var stream = null
var scanning = false
var detector = null
var jsQR = null

// jsQR roda na CPU: lê ~8 quadros/s em resolução reduzida.
var JSQR_INTERVAL_MS = 120
var JSQR_MAX_WIDTH = 640

onMounted(start)
onUnmounted(stop)

async function start() {
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    emit('error', 'Seu navegador não permite usar a câmera aqui. Escaneie o QR Code com o app de câmera do celular.')
    return
  }

  try {
    if (window.BarcodeDetector) {
      detector = new window.BarcodeDetector({ formats: ['qr_code'] })
    } else {
      jsQR = (await import('jsqr')).default
    }
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
  } catch (err) {
    emit('error', 'Não foi possível acessar a câmera. Verifique as permissões do navegador.')
    return
  }

  var video = videoEl.value
  if (!video) { stop(); return }
  video.srcObject = stream
  try { await video.play() } catch (e) { /* autoplay bloqueado — o playsinline resolve no iOS */ }

  scanning = true
  requestAnimationFrame(scanFrame)
}

async function scanFrame() {
  if (!scanning) return

  var video = videoEl.value
  if (!video || video.readyState !== video.HAVE_ENOUGH_DATA) {
    requestAnimationFrame(scanFrame)
    return
  }

  var raw = null
  try {
    raw = detector ? await detectNative(video) : detectJs(video)
  } catch (e) { /* quadro ruim — tenta o próximo */ }

  if (raw) {
    var parsed = parseScannedUrl(raw)
    if (parsed) {
      stop()
      emit('result', parsed)
      return
    }
    hint.value = 'Este QR Code não é de uma mesa. Aponte para o código da mesa.'
  }

  if (detector) requestAnimationFrame(scanFrame)
  else setTimeout(function() { requestAnimationFrame(scanFrame) }, JSQR_INTERVAL_MS)
}

async function detectNative(video) {
  var codes = await detector.detect(video)
  return codes.length > 0 ? codes[0].rawValue : null
}

function detectJs(video) {
  var canvas = canvasEl.value
  var scale = Math.min(1, JSQR_MAX_WIDTH / video.videoWidth)
  var w = Math.round(video.videoWidth * scale)
  var h = Math.round(video.videoHeight * scale)
  canvas.width = w
  canvas.height = h

  var ctx = canvas.getContext('2d', { willReadFrequently: true })
  ctx.drawImage(video, 0, 0, w, h)
  var code = jsQR(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' })
  return code ? code.data : null
}

function stop() {
  scanning = false
  if (stream) {
    stream.getTracks().forEach(function(track) { track.stop() })
    stream = null
  }
}

function close() {
  stop()
  emit('close')
}
</script>

<template>
  <div class="scanner-overlay">
    <div class="scanner-header">
      <button class="scanner-close" @click="close">✕ Fechar</button>
    </div>
    <div class="scanner-viewport">
      <video ref="videoEl" playsinline autoplay muted class="scanner-video"></video>
      <canvas ref="canvasEl" style="display:none"></canvas>
      <div class="scanner-frame">
        <div class="scanner-corner tl"></div>
        <div class="scanner-corner tr"></div>
        <div class="scanner-corner bl"></div>
        <div class="scanner-corner br"></div>
      </div>
      <div class="scanner-hint">{{ hint }}</div>
    </div>
  </div>
</template>

<style scoped>
.scanner-overlay {
  position: fixed;
  inset: 0;
  background: #000;
  z-index: var(--z-modal);
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
  background: rgba(255, 255, 255, 0.15);
  border: none;
  color: #FFFFFF;
  padding: 8px 18px;
  border-radius: var(--radius-pill);
  font-size: var(--fs-sm);
  font-weight: var(--fw-semibold);
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
  border-color: var(--brand-accent);
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
  left: 16px;
  right: 16px;
  color: rgba(255, 255, 255, 0.85);
  font-size: var(--fs-sm);
  font-weight: var(--fw-medium);
  text-align: center;
}
</style>
