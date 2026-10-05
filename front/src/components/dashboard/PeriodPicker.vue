<template>
  <div
    class="period-picker"
    role="radiogroup"
    aria-label="Période"
    data-testid="period-picker"
  >
    <button
      v-for="p in PERIODS"
      :key="p.days"
      type="button"
      role="radio"
      class="period-picker__option"
      :class="{ 'period-picker__option--on': p.days === modelValue }"
      :aria-checked="p.days === modelValue"
      :data-testid="`period-${p.days}`"
      @click="emit('update:modelValue', p.days)"
    >
      {{ p.label }}
    </button>
  </div>
</template>

<script setup>
import { PERIODS } from '@/utils/dashboard/stats'

/** The statistics page's period: 7 days, 30 days, 90 days or a year (one row above the blocks). */
defineProps({
  modelValue: { type: Number, required: true }
})

const emit = defineEmits({
  'update:modelValue': days => typeof days === 'number'
})
</script>

<style scoped lang="scss">
.period-picker {
  display: inline-flex;
  gap: 4px;
  padding: 4px;
  background: var(--cm-subtle);
  border-radius: 999px;
}

.period-picker__option {
  height: 34px;
  padding: 0 14px;
  border: none;
  border-radius: 999px;
  background: transparent;
  color: var(--cm-ink-soft);
  font: inherit;
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;

  &--on {
    background: var(--cm-surface);
    color: var(--cm-ink);
    box-shadow: 0 1px 3px rgba(27, 21, 48, 0.12);
  }
}
</style>
