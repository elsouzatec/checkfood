// ── garcom/vite.config.js ──
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

export default defineConfig({
  plugins: [vue()],
  base: '/garcom/',
  resolve: {
    alias: {
      '@shared': resolve(__dirname, '../../shared'),
    },
  },
})
