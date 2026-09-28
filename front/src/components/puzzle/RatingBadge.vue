<template>
  <q-chip
    v-if="store.rating"
    dense
    square
    icon="extension"
    :label="label"
    data-testid="puzzle-rating"
  >
    <q-tooltip>Classement puzzles (±{{ store.rating.deviation }})</q-tooltip>
  </q-chip>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { usePuzzleStore } from '@/stores/puzzle'

const store = usePuzzleStore()

/** "1500?" while provisional, like Lichess. */
const label = computed(() =>
  store.rating
    ? `${store.rating.rating}${store.rating.provisional ? '?' : ''}`
    : ''
)

onMounted(() => {
  if (!store.rating) store.fetchRating().catch(() => {})
})
</script>
