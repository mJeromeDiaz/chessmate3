<template>
  <div class="column q-gutter-xs" data-testid="parcours-header">
    <div class="row items-center q-gutter-sm">
      <div>
        <div class="parcours-header__tier" data-testid="parcours-tier">{{
          $t('parcours.run.tier', { n: tier + 1, total: tiers })
        }}</div>
        <div class="text-h6 text-weight-medium">{{ name }}</div>
      </div>
      <q-space />
      <div class="text-h5 text-weight-medium" data-testid="run-progress">{{
        $t('parcours.run.progress', { n: exercise, count })
      }}</div>
      <q-btn
        flat
        no-caps
        color="negative"
        icon="stop"
        :label="$t('training.stop')"
        data-testid="run-stop"
        @click="emit('stop')"
      />
    </div>
    <q-linear-progress
      :value="count ? (exercise - 1) / count : 0"
      color="orange"
      class="q-my-xs"
    />
    <slot />
  </div>
</template>

<script setup>
/**
 * The top of a parcours' tier (docs/PARCOURS.md), in place of the timed run's clock: the tier out
 * of five, what it plays, the exercise in progress out of its count, "Terminer".
 */
defineProps({
  /** 0 to 4. */
  tier: { type: Number, required: true },
  tiers: { type: Number, required: true },
  name: { type: String, required: true },
  /** The exercise in progress, 1-based. */
  exercise: { type: Number, required: true },
  count: { type: Number, required: true }
})

const emit = defineEmits({ stop: null })
</script>

<style scoped>
.parcours-header__tier {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: #b84a0e;
}
.body--dark .parcours-header__tier {
  color: #ffb37f;
}
</style>
