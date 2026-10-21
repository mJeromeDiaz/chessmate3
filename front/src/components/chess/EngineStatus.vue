<template>
  <div
    v-if="status !== 'idle' && status !== 'ready'"
    class="engine-status"
    :class="`engine-status--${status}`"
    :data-status="status"
    data-testid="engine-status"
    role="status"
  >
    <q-spinner v-if="status !== 'failed'" size="14px" />
    <q-icon v-else name="warning" size="16px" />
    <span>{{ $t(`chess.engine.${status}`) }}</span>
  </div>
</template>

<script setup>
/**
 * What the engine is doing, in a line (docs/ENGINE.md): loading, thinking, failed to start;
 * nothing while it waits, ready.
 */
defineProps({
  /** @type {import('vue').PropType<import('@/composables/engine/useEngine').EngineStatus>} */
  status: { type: String, required: true }
})
</script>

<style scoped>
.engine-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  opacity: 0.8;
}

.engine-status--failed {
  color: var(--q-negative);
  opacity: 1;
}
</style>
