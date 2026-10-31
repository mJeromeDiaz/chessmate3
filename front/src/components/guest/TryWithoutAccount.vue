<template>
  <button
    type="button"
    class="landing-btn"
    :disabled="busy"
    data-testid="landing-try"
    @click="start"
  >
    {{ busy ? $t('guest.try.busy') : $t('guest.try.label') }}
  </button>
</template>

<script setup>
import { ref } from 'vue'
import { useQuasar } from 'quasar'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'

/**
 * « Essayer sans compte » (docs/GUEST.md): opens a guest account in this browser, then its home.
 * The landing page styles it (`landing-btn` and a variant passed as a class).
 */
const $q = useQuasar()
const router = useRouter()
const auth = useAuthStore()
const busy = ref(false)

async function start() {
  busy.value = true
  try {
    await auth.startGuest()
    await router.push('/')
  } catch (e) {
    $q.notify({ type: 'negative', message: apiErrorMessage(e) })
  } finally {
    busy.value = false
  }
}
</script>
