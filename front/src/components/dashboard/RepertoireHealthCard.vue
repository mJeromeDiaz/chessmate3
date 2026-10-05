<template>
  <section class="cm-card health-card" data-testid="repertoire-health">
    <h2 class="cm-card__title">Répertoires</h2>

    <p
      v-if="!health.repertoires"
      class="cm-muted q-my-md"
      data-testid="health-empty"
    >
      Aucun répertoire pour l’instant.
      <router-link to="/repertoire">Créer un répertoire →</router-link>
    </p>

    <template v-else>
      <div class="health-card__figures">
        <div class="health-card__figure" data-testid="health-due">
          <div class="health-card__value">{{ health.cards.due }}</div>
          <div class="health-card__label">Positions dues</div>
        </div>
        <div class="health-card__figure" data-testid="health-new">
          <div class="health-card__value">{{ health.cards.new }}</div>
          <div class="health-card__label">Jamais vues</div>
        </div>
        <div class="health-card__figure" data-testid="health-success">
          <div class="health-card__value">{{
            formatPercent(health.tests.successRate)
          }}</div>
          <div class="health-card__label"
            >Réussite ({{ health.tests.total }} test{{
              health.tests.total > 1 ? 's' : ''
            }})</div
          >
        </div>
      </div>

      <h3 class="health-card__h3">Tronçons fragiles</h3>
      <p v-if="!health.fragile.length" class="cm-muted q-mb-none">
        Aucun : rien ne flanche dans tes derniers tests.
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
          <div class="cm-muted"
            >{{ f.repertoireName }} · {{ f.tests }} tests</div
          >
        </div>
        <strong class="health-card__rate"
          >{{ formatPercent(f.recentFailureRate) }} ratés</strong
        >
      </router-link>
      <p class="health-card__note">
        Fragile : au moins 3 tests et des échecs parmi les 10 derniers, sur
        toute l’histoire.
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
