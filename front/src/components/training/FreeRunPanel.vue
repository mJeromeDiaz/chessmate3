<template>
  <div class="free-run column q-gutter-md" data-testid="free-run">
    <slot name="header" />
    <q-card flat bordered>
      <q-card-section>
        <div class="text-caption text-grey">Temps libre</div>
        <div class="text-h5" data-testid="free-run-format">{{ format }}</div>
        <div
          v-if="notes"
          class="free-run__notes q-mt-sm"
          data-testid="free-run-notes"
          >{{ notes }}</div
        >
      </q-card-section>
      <q-card-section class="text-body2 text-grey-8">
        Lis, regarde, écoute à ton rythme : le temps est compté jusqu’à la fin
        du chrono ou jusqu’à « Terminer ».
      </q-card-section>
    </q-card>
  </div>
</template>

<script setup>
/**
 * The free study module in a timed run (docs/TRAINING.md): nothing to play, the countdown (in the
 * header slot), the format and the user's notes.
 */
import { computed } from 'vue'
import { FREE_FORMATS } from '@/utils/training'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').RunItem>} */
  item: { type: Object, required: true }
})

const format = computed(
  () => FREE_FORMATS[props.item.data.format] ?? props.item.data.format
)
const notes = computed(() => props.item.data.notes ?? '')
</script>

<style scoped>
.free-run {
  max-width: 700px;
  margin: 0 auto;
}

.free-run__notes {
  white-space: pre-line;
}
</style>
