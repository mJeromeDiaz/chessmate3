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

const props = defineProps({
  /** @type {import('vue').PropType<{total: number, new: number, learning: number, review: number, due: number}>} */
  cards: { type: Object, required: true }
})

const stats = computed(() => [
  { label: 'Dues maintenant', value: props.cards.due, testid: 'cards-due' },
  { label: 'Nouvelles', value: props.cards.new, testid: 'cards-new' },
  {
    label: 'En apprentissage',
    value: props.cards.learning,
    testid: 'cards-learning'
  },
  { label: 'En révision', value: props.cards.review, testid: 'cards-review' }
])
</script>
