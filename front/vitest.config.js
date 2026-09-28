import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

// Unit tests of the plain-JS layers (HTTP client, store, guards): no Quasar build needed.
export default defineConfig({
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) }
  },
  test: {
    environment: 'happy-dom',
    include: ['tests/unit/**/*.test.js'],
    restoreMocks: true
  }
})
