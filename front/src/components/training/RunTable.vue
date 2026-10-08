<template>
  <div class="run-list" data-testid="run-table">
    <div
      v-for="row in rows"
      :key="row.id"
      class="run-row"
      data-testid="run-row"
    >
      <div class="run-row__date">{{ formatDate(row.startedAt) }}</div>
      <div class="run-row__stats">
        <div>
          <div class="run-row__value">{{
            formatDuration(row.summary?.durationMs)
          }}</div>
          <div class="run-row__label">durée</div>
        </div>
        <div>
          <div class="run-row__value">{{ row.summary?.itemCount ?? '—' }}</div>
          <div class="run-row__label">terminés</div>
        </div>
        <div>
          <div class="run-row__value">{{
            row.summary?.successCount ?? '—'
          }}</div>
          <div class="run-row__label">réussis</div>
        </div>
        <div>
          <div class="run-row__value">{{
            formatPercent(row.summary?.successRate)
          }}</div>
          <div class="run-row__label">précision</div>
        </div>
        <div>
          <div class="run-row__value">{{
            perMinute(row.summary?.itemsPerMinute)
          }}</div>
          <div class="run-row__label">par minute</div>
        </div>
        <div>
          <div
            class="run-row__value"
            :class="
              row.trend > 0
                ? 'text-positive'
                : row.trend < 0
                  ? 'text-negative'
                  : ''
            "
            >{{
              row.trend === null
                ? '—'
                : `${row.trend > 0 ? '+' : ''}${perMinute(row.trend)}`
            }}</div
          >
          <div class="run-row__label">évolution</div>
        </div>
      </div>
    </div>
    <div v-if="rows.length === 0" class="run-list__empty"
      >Aucune séance pour l’instant.</div
    >
  </div>
</template>

<script setup>
/**
 * The closed runs of a subject, newest first, with the change in items per minute since the
 * previous run (budgets vary: per minute is what compares).
 */
import { computed } from 'vue'
import { formatDate, formatDuration, formatPercent } from '@/utils/format'
import { runTrends } from '@/utils/training'

const props = defineProps({
  /** @type {import('vue').PropType<{id: string, startedAt: string, summary: import('@/composables/training/useTimeboxedRun').RunSummary|null}[]>} newest first */
  runs: { type: Array, required: true }
})

const rows = computed(() => runTrends(props.runs))

/** @param {number|null|undefined} value */
function perMinute(value) {
  return value === null || value === undefined
    ? '—'
    : value.toLocaleString('fr-FR', { maximumFractionDigits: 2 })
}
</script>

<style scoped lang="scss">
.run-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.run-row {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 16px;
  border-radius: 16px;
  background: var(--cm-surface);
  border: 1px solid var(--cm-line);
}

.run-row__date {
  font-size: 16px;
  font-weight: 700;
}

.run-row__stats {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px 12px;
}

.run-row__value {
  font-size: 16px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.run-row__label {
  font-size: 13px;
  color: var(--cm-muted);
}

.run-list__empty {
  font-size: 15px;
  color: var(--cm-muted);
}

@media (min-width: 1024px) {
  .run-row {
    flex-direction: row;
    align-items: center;
    padding: 16px 22px;
  }

  .run-row__date {
    flex: 0 0 170px;
    font-size: 17px;
  }

  .run-row__stats {
    flex: 1;
    grid-template-columns: repeat(6, minmax(0, 1fr));
  }

  .run-row__value {
    font-size: 18px;
  }

  .run-row__label {
    font-size: 14px;
  }
}
</style>
