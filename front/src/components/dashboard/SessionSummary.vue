<template>
  <section class="cm-card session-summary" data-testid="session-summary">
    <div class="session-summary__head">
      <h2 class="cm-card__title">Sessions</h2>
      <span class="cm-muted">{{
        sessions.closed
          ? `${sessions.closed} jouée${sessions.closed > 1 ? 's' : ''} · ${formatHours(sessions.playedMs)}`
          : 'Aucune sur la période'
      }}</span>
    </div>
    <div class="session-summary__figures">
      <div
        v-for="figure in figures"
        :key="figure.id"
        class="session-summary__figure"
        :data-testid="`sessions-${figure.id}`"
      >
        <div class="session-summary__value">{{ figure.value }}</div>
        <div class="session-summary__label">{{ figure.label }}</div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import { formatHours, sessionFigures } from '@/utils/dashboard/stats'

/**
 * The sessions of the period (statistics page): by final status, and the average time played in
 * those where something was played.
 */
const props = defineProps({
  /** @type {import('vue').PropType<import('@/stores/dashboard').Training['sessions']>} */
  sessions: { type: Object, required: true }
})

const figures = computed(() => sessionFigures(props.sessions))
</script>

<style scoped lang="scss">
.session-summary__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  font-size: 13px;
}

.session-summary__figures {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-top: 12px;

  @media (min-width: $breakpoint-sm-min) {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

.session-summary__figure {
  padding: 10px 12px;
  background: var(--cm-subtle);
  border-radius: 14px;
}

.session-summary__value {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
  font-variant-numeric: tabular-nums;
}

.session-summary__label {
  font-size: 12px;
  color: var(--cm-muted);
}
</style>
