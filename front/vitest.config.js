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
    // French messages, as the texts the tests expect (docs/I18N.md).
    setupFiles: ['tests/unit/setup.js'],
    restoreMocks: true
  }
})
