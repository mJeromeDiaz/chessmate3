<template>
  <div class="column q-gutter-xs">
    <div class="row items-center q-gutter-sm">
      <div class="text-h4 text-weight-medium" data-testid="run-timer">{{
        formatCountdown(remainingMs)
      }}</div>
      <q-space />
      <q-btn
        flat
        no-caps
        color="negative"
        icon="stop"
        label="Terminer"
        data-testid="run-stop"
        @click="emit('stop')"
      />
    </div>
    <q-linear-progress :value="elapsedRatio" class="q-my-xs" />
    <slot />
  </div>
</template>

<script setup>
/**
 * The top of a timed run, whatever its module: time left on the server's clock, "Terminer",
 * elapsed time bar; the default slot shows the module's progress.
 */
import { computed } from 'vue'
import { formatCountdown } from '@/utils/format'

const props = defineProps({
  remainingMs: { type: Number, required: true },
  budgetSeconds: { type: Number, required: true }
})

const emit = defineEmits({ stop: null })

const elapsedRatio = computed(
  () => 1 - props.remainingMs / (props.budgetSeconds * 1000)
)
</script>
