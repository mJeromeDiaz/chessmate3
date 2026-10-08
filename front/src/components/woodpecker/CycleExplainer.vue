<template>
  <q-expansion-item
    class="cycle-explainer"
    icon="help_outline"
    :label="
      light ? 'Comment marche le mode light ?' : 'Comment marchent les cycles ?'
    "
    header-class="cycle-explainer__header"
    data-testid="cycle-explainer"
  >
    <div class="cycle-explainer__body">
      <div
        v-for="point in points"
        :key="point.title"
        class="cycle-explainer__point"
      >
        <div class="cycle-explainer__title">{{ point.title }}</div>
        <div class="cycle-explainer__text">{{ point.text }}</div>
      </div>
    </div>
  </q-expansion-item>
</template>

<script setup>
/**
 * The Woodpecker method in a few points, worded from the set's own settings (cycle lengths,
 * rest, shuffle), folded by default.
 */
import { computed } from 'vue'
import { explainCycles } from '@/utils/woodpecker'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/stores/woodpecker').WoodpeckerSet>} */
  set: { type: Object, required: true }
})

const light = computed(() => props.set.mode === 'light')
const points = computed(() => explainCycles(/** @type {any} */ (props.set)))
</script>

<style scoped lang="scss">
.cycle-explainer {
  border-radius: 16px;
  background: var(--cm-surface);
  border: 1px solid var(--cm-line);
  overflow: hidden;
}

:deep(.cycle-explainer__header) {
  font-size: 16px;
  font-weight: 700;
  min-height: 56px;
}

.cycle-explainer__body {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: 14px;
  padding: 4px 18px 18px;
}

.cycle-explainer__title {
  font-size: 15px;
  font-weight: 700;
  color: var(--cm-brand-deep);
}

.cycle-explainer__text {
  font-size: 15px;
  line-height: 1.5;
}

@media (min-width: 1024px) {
  .cycle-explainer__body {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px 28px;
  }

  .cycle-explainer__text {
    font-size: 16px;
  }
}
</style>
