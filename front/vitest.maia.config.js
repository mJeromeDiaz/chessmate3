import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vitest/config'

// The real Maia model under Node (docs/ENGINE.md, § Maia): `npm run test:maia`, after
// `npm run maia:fetch`. Slow (the model loads in seconds), so kept out of `npm test`.
export default defineConfig({
  resolve: {
    alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) }
  },
  test: {
    environment: 'node',
    include: ['tests/maia/**/*.test.js'],
    testTimeout: 60_000,
    hookTimeout: 120_000
  }
})
