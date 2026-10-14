<template>
  <section class="cm-card health-card" data-testid="repertoire-health">
    <h2 class="cm-card__title">{{ $t('dashboard.health.title') }}</h2>

    <p
      v-if="!health.repertoires"
      class="cm-muted q-my-md"
      data-testid="health-empty"
    >
      {{ $t('dashboard.health.empty') }}
      <router-link to="/repertoire">{{
        $t('dashboard.health.create')
      }}</router-link>
    </p>

    <template v-else>
      <div class="health-card__figures">
        <div class="health-card__figure" data-testid="health-due">
          <div class="health-card__value">{{ health.cards.due }}</div>
          <div class="health-card__label">{{ $t('dashboard.health.due') }}</div>
        </div>
        <div class="health-card__figure" data-testid="health-new">
          <div class="health-card__value">{{ health.cards.new }}</div>
          <div class="health-card__label">{{ $t('dashboard.health.new') }}</div>
        </div>
        <div class="health-card__figure" data-testid="health-success">
          <div class="health-card__value">{{
            formatPercent(health.tests.successRate)
          }}</div>
          <div class="health-card__label">{{
            $t(
              'dashboard.health.success',
              { n: health.tests.total },
              health.tests.total > 1 ? 2 : 1
            )
          }}</div>
        </div>
      </div>

      <h3 class="health-card__h3">{{ $t('dashboard.health.fragileTitle') }}</h3>
      <p v-if="!health.fragile.length" class="cm-muted q-mb-none">
        {{ $t('dashboard.health.noFragile') }}
      </p>
      <router-link
        v-for="f in health.fragile"
        :key="f.segmentId"
        :to="`/repertoire/${f.repertoireId}/stats`"
        class="health-card__row"
        data-testid="health-fragile"
      >
        <div class="health-card__row-main">
          <div class="health-card__row-title">{{ labelText(f.label) }}</div>
          <div class="cm-muted">{{
            $t('dashboard.health.fragileMeta', {
              name: f.repertoireName,
              n: f.tests
            })
          }}</div>
        </div>
        <strong class="health-card__rate">{{
          $t('dashboard.health.failures', {
            pct: formatPercent(f.recentFailureRate)
          })
        }}</strong>
      </router-link>
      <p class="health-card__note">
        {{ $t('dashboard.health.note') }}
      </p>
    </template>
  </section>
</template>

<script setup>
import { formatPercent } from '@/utils/format'
import { labelText } from '@/utils/repertoireTest'

/**
 * The health of every repertoire (statistics page): positions due now, success in the tests of
 * the period, the most fragile segments (each opens its repertoire's statistics).
 */
defineProps({
  /** @type {import('vue').PropType<import('@/stores/dashboard').RepertoireHealth>} */
  health: { type: Object, required: true }
})
</script>

<style scoped lang="scss">
.health-card__figures {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-top: 12px;
}

.health-card__figure {
  padding: 10px 12px;
  background: var(--cm-subtle);
  border-radius: 14px;
}

.health-card__value {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
  font-variant-numeric: tabular-nums;
}

.health-card__label {
  font-size: 12px;
  color: var(--cm-muted);
}

.health-card__h3 {
  margin: 16px 0 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.health-card__row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 0;
  border-top: 1px solid var(--cm-line);
  color: inherit;
  text-decoration: none;
  font-size: 13px;

  &:hover .health-card__row-title {
    text-decoration: underline;
  }
}

.health-card__row-main {
  flex: 1;
  min-width: 0;
}

.health-card__row-title {
  overflow: hidden;
  font-weight: 700;
  font-size: 14px;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.health-card__rate {
  flex: none;
  font-variant-numeric: tabular-nums;
}

.health-card__note {
  margin: 10px 0 0;
  font-size: 12px;
  color: var(--cm-muted);
}
</style>
