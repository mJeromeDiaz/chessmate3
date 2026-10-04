<template>
  <div class="push-toggle" data-testid="push-toggle">
    <span class="push-toggle__text" data-testid="push-state">{{
      state ? PUSH_STATE_TEXT[state] : 'Vérification…'
    }}</span>
    <q-btn
      v-if="state === 'unsubscribed'"
      dense
      unelevated
      no-caps
      color="primary"
      :label="compact ? 'Activer sur cet appareil' : 'Activer'"
      :loading="busy"
      data-testid="push-enable"
      @click="enable"
    />
    <q-btn
      v-else-if="state === 'subscribed' && !compact"
      dense
      flat
      no-caps
      color="negative"
      label="Désactiver"
      :loading="busy"
      data-testid="push-disable"
      @click="disable"
    />
    <span v-if="error" class="push-toggle__error">{{ error }}</span>
  </div>
</template>

<script setup>
/**
 * Web Push on this device (docs/NOTIFICATIONS.md): its state, and a button to turn it on (the
 * browser asks for the permission) or off. Compact: only "turn on", e.g. next to a reminder.
 */
import { onMounted, ref } from 'vue'
import {
  PUSH_STATE_TEXT,
  pushState,
  subscribePush,
  unsubscribePush
} from '@/utils/push'

defineProps({
  compact: { type: Boolean, default: false }
})

/** @type {import('vue').Ref<import('@/utils/push').PushState|null>} */
const state = ref(null)
const busy = ref(false)
const error = ref('')

/** @param {() => Promise<import('@/utils/push').PushState>} action */
async function run(action) {
  busy.value = true
  error.value = ''
  try {
    state.value = await action()
  } catch {
    error.value = 'Impossible de modifier les notifications de cet appareil.'
  } finally {
    busy.value = false
  }
}

const enable = () => run(subscribePush)
const disable = () => run(unsubscribePush)

onMounted(() => run(() => pushState()))
</script>

<style scoped>
.push-toggle {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 12px;
}

.push-toggle__text {
  color: var(--cm-ink-soft);
  font-size: 13.5px;
}

.push-toggle__error {
  width: 100%;
  color: var(--cm-danger);
  font-size: 13px;
}
</style>
