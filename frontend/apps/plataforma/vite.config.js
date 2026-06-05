// ── plataforma/vite.config.js ──
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  base: '/',
  resolve: {
    alias: {
      '@shared': resolve(__dirname, '../../shared'),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5177,
  },
})
