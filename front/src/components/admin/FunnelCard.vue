<template>
  <section class="cm-card funnel" data-testid="admin-funnel">
    <div class="funnel__head">
      <h2 class="cm-card__title">Invitations de la période</h2>
      <span class="funnel__summary"
        >Conversion {{ formatPercent(invitations.conversionRate) }}</span
      >
    </div>

    <p v-if="invitations.created === 0" class="cm-muted q-my-md">
      Aucune invitation créée sur la période.
    </p>

    <ul v-else class="funnel__rows">
      <li v-for="row in rows" :key="row.id" :data-testid="`funnel-${row.id}`">
        <span class="funnel__label">{{ row.label }}</span>
        <span class="funnel__track">
          <span class="funnel__bar" :style="{ width: `${row.share * 100}%` }" />
        </span>
        <strong class="funnel__count">{{ row.count }}</strong>
      </li>
    </ul>

    <p class="funnel__foot cm-muted">
      Délai médian avant inscription :
      {{ formatWait(invitations.medianSecondsToUse) }} · Clés utilisables
      aujourd’hui : {{ invitations.pendingNow }}
    </p>
  </section>
</template>

<script setup>
import { computed } from 'vue'
import { formatPercent } from '@/utils/format'
import { formatWait, funnelRows } from '@/utils/admin/charts'

/** What became of the invitations created during the period, each row against those created. */
const props = defineProps({
  /** @type {import('vue').PropType<Record<string, any>>} the `invitations` block of the stats */
  invitations: { type: Object, required: true }
})

const rows = computed(() => funnelRows(props.invitations))
</script>

<style scoped lang="scss">
.funnel__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
}

.funnel__summary {
  font-weight: 700;
}

.funnel__rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin: 12px 0;
  padding: 0;
  list-style: none;

  li {
    display: grid;
    grid-template-columns: 120px 1fr 40px;
    align-items: center;
    gap: 10px;
    font-size: 13px;
  }
}

.funnel__label {
  color: var(--cm-ink-soft);
}

.funnel__track {
  height: 12px;
  background: var(--cm-subtle);
  border-radius: 4px;
  overflow: hidden;
}

.funnel__bar {
  display: block;
  height: 100%;
  background: var(--cm-brand);
  border-radius: 0 4px 4px 0;
}

.funnel__count {
  text-align: right;
}

.funnel__foot {
  margin: 0;
  font-size: 12px;
}
</style>
