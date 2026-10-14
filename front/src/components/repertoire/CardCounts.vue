<template>
  <div class="row q-col-gutter-md" data-testid="card-counts">
    <div v-for="stat in stats" :key="stat.label" class="col-6 col-sm-3">
      <div class="text-caption text-grey">{{ stat.label }}</div>
      <div class="text-h6" :data-testid="stat.testid">{{ stat.value }}</div>
    </div>
  </div>
</template>

<script setup>
/**
 * Spaced-repetition cards of prepared moves: due now, new, learning, in review.
 */
import { computed } from 'vue'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<{total: number, new: number, learning: number, review: number, due: number}>} */
  cards: { type: Object, required: true }
})

const stats = computed(() =>
  ['due', 'new', 'learning', 'review'].map(key => ({
    label: t(`repertoire.cards.${key}`),
    value: props.cards[key],
    testid: `cards-${key}`
  }))
)
</script>
