<template>
  <div
    class="tour-goals"
    role="radiogroup"
    aria-label="Ton objectif"
    data-testid="tour-goals"
  >
    <div class="tour-goals__hint">Un seul choix</div>
    <button
      v-for="(goal, i) in GOALS"
      :key="goal.id"
      type="button"
      role="radio"
      class="tour-choice tour-goals__row tour-in"
      :class="{ 'tour-choice--on': modelValue === goal.id }"
      :aria-checked="modelValue === goal.id"
      :style="{ animationDelay: `${i * 0.05}s` }"
      :data-testid="`tour-goal-${goal.id}`"
      @click="emit('update:modelValue', goal.id)"
    >
      <span class="tour-goals__glyph" aria-hidden="true">{{ goal.glyph }}</span>
      <span class="tour-goals__label">{{ goal.label }}</span>
      <span class="tour-radio" aria-hidden="true" />
    </button>
  </div>
</template>

<script setup>
/**
 * The tour's goal screen: one goal, as radio buttons.
 */
import { GOALS } from '@/utils/onboarding'

defineProps({
  modelValue: { type: String, default: null }
})

const emit = defineEmits({
  /** @param {string} id */
  'update:modelValue': id => typeof id === 'string'
})
</script>

<style scoped lang="scss">
.tour-goals {
  display: flex;
  flex-direction: column;
  gap: 9px;
  padding-bottom: 4px;
}

.tour-goals__hint {
  padding: 0 4px;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--cm-muted);
}

.tour-goals__row {
  min-height: 50px;
  padding: 0 12px 0 14px;
  gap: 10px;
  font-weight: 700;
  font-size: 14.5px;
}

.tour-goals__glyph {
  width: 20px;
  font-size: 18px;
  text-align: center;
}

.tour-goals__label {
  flex: 1;
}
</style>
