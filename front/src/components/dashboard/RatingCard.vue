<template>
  <section class="cm-card rating-card" data-testid="rating-card">
    <div class="rating-card__head">
      <div>
        <div class="rating-card__kicker">{{ kicker }}</div>
        <div v-if="curve" class="rating-card__value">
          <span class="rating-card__current" data-testid="rating-current">{{
            curve.current
          }}</span>
          <span
            class="rating-card__delta"
            :class="{ 'rating-card__delta--down': curve.delta < 0 }"
            data-testid="rating-delta"
            >{{ curve.delta >= 0 ? '▲' : '▼' }}
            {{ formatRatingDelta(curve.delta) }}</span
          >
          <span class="rating-card__record gt-sm">Record {{ curve.max }}</span>
        </div>
      </div>
      <div class="rating-card__tabs" role="tablist">
        <button
          v-for="tab in TABS"
          :key="tab.id"
          type="button"
          role="tab"
          class="rating-card__tab"
          :class="{ 'rating-card__tab--on': tab.id === selected }"
          :aria-selected="tab.id === selected"
          :data-testid="`rating-tab-${tab.id}`"
          @click="select(tab.id)"
        >
          {{ tab.label }}
        </button>
      </div>
    </div>

    <div v-if="busy" class="rating-card__chart">
      <q-skeleton height="100%" />
    </div>
    <div
      v-else-if="notice"
      class="rating-card__notice"
      data-testid="rating-notice"
    >
      <span>{{ notice.text }}</span>
      <q-btn
        v-if="notice.action"
        flat
        dense
        no-caps
        color="primary"
        :label="notice.action.label"
        :to="notice.action.to"
        @click="notice.action.click?.()"
      />
    </div>
    <template v-else-if="curve">
      <div class="rating-card__chart">
        <svg
          viewBox="0 0 100 100"
          preserveAspectRatio="none"
          aria-hidden="true"
        >
          <path :d="curve.area" class="rating-card__area" />
          <path
            :d="curve.line"
            class="rating-card__line"
            vector-effect="non-scaling-stroke"
          />
        </svg>
        <div class="rating-card__dot" :style="{ top: `${curve.dotTop}%` }" />
      </div>
      <div class="rating-card__ticks lt-md">
        <span>{{ curve.min }} min</span>
        <span>Record {{ curve.max }}</span>
      </div>
      <div class="rating-card__ticks gt-sm">
        <span v-for="tick in ticks" :key="tick.label">{{ tick.label }}</span>
        <span>Aujourd’hui</span>
      </div>
    </template>
  </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useDashboardStore, CURVE_DAYS } from '@/stores/dashboard'
import { buildCurve, monthTicks } from '@/utils/dashboard/curve'
import { formatRatingDelta } from '@/utils/format'

/**
 * The rating curve over 90 days (design "Dashboard"): the puzzle rating, or the blitz / rapid /
 * classical rating of the linked Lichess account, asked on the first click on one of those tabs.
 */

const TABS = [
  { id: 'puzzles', label: 'Puzzles', kicker: 'CLASSEMENT PUZZLES' },
  { id: 'blitz', label: 'Blitz', kicker: 'ELO LICHESS · BLITZ' },
  { id: 'rapid', label: 'Rapide', kicker: 'ELO LICHESS · RAPIDE' },
  { id: 'classical', label: 'Classique', kicker: 'ELO LICHESS · CLASSIQUE' }
]

const store = useDashboardStore()
/** @type {import('vue').Ref<'puzzles'|'blitz'|'rapid'|'classical'>} */
const selected = ref('puzzles')

const kicker = computed(
  () =>
    `${TABS.find(t => t.id === selected.value)?.kicker} · ${CURVE_DAYS} JOURS`
)

/** The period and points of the selected tab, null while unknown. */
const source = computed(() => {
  if (selected.value === 'puzzles') return store.rating
  const lichess = store.lichess
  if (!lichess?.linked) return null
  return {
    from: lichess.from,
    today: lichess.today,
    points: lichess.perfs[selected.value]
  }
})

const curve = computed(() =>
  source.value
    ? buildCurve(source.value.points, source.value.from, source.value.today)
    : null
)
/** Month names under the curve; a month that only just started would crowd "Aujourd’hui". */
const ticks = computed(() =>
  source.value
    ? monthTicks(source.value.from, source.value.today).filter(t => t.left < 90)
    : []
)

const busy = computed(() =>
  selected.value === 'puzzles'
    ? store.loading && !store.rating
    : store.lichessLoading
)

/** Why there is no curve to draw, and what to do about it. */
const notice = computed(() => {
  if (selected.value === 'puzzles') {
    if (store.errors.rating)
      return {
        text: store.errors.rating,
        action: { label: 'Réessayer', click: () => store.load() }
      }
    if (store.rating && !store.rating.points.length) {
      return {
        text: 'Joue des puzzles classés pour voir ton classement évoluer.',
        action: { label: 'Résoudre des puzzles', to: '/puzzle' }
      }
    }
    return null
  }
  if (store.lichessError)
    return {
      text: store.lichessError,
      action: { label: 'Réessayer', click: () => store.loadLichess() }
    }
  if (store.lichess && !store.lichess.linked) {
    return {
      text: 'Lie ton compte Lichess pour suivre ton Elo en partie.',
      action: { label: 'Lier Lichess', to: '/profile' }
    }
  }
  if (store.lichess && !curve.value) {
    return {
      text: 'Aucune partie classée dans cette cadence sur Lichess.',
      action: null
    }
  }
  return null
})

/**
 * @param {'puzzles'|'blitz'|'rapid'|'classical'} tab
 */
function select(tab) {
  selected.value = tab
  if (tab !== 'puzzles') store.loadLichess()
}
</script>

<style scoped lang="scss">
.rating-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.rating-card__head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}

.rating-card__kicker {
  font-size: 11px;
  font-weight: 700;
  color: var(--cm-muted);

  @media (min-width: $breakpoint-md-min) {
    font-size: 12px;
  }
}

.rating-card__value {
  display: flex;
  align-items: baseline;
  gap: 10px;
}

.rating-card__current {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 32px;

  @media (min-width: $breakpoint-md-min) {
    font-size: 40px;
  }
}

.rating-card__delta {
  font-weight: 700;
  font-size: 14px;
  color: #5a7a00;

  &--down {
    color: #c02670;
  }
}

.body--dark .rating-card__delta {
  color: var(--cm-lime-ink);

  &--down {
    color: #ff8fc0;
  }
}

.rating-card__record {
  font-size: 13px;
  color: var(--cm-muted);
}

.rating-card__tabs {
  display: flex;
  gap: 6px;
  width: 100%;

  @media (min-width: $breakpoint-md-min) {
    width: auto;
  }
}

.rating-card__tab {
  flex: 1;
  height: 34px;
  padding: 0 12px;
  border: none;
  border-radius: 11px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font: inherit;
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;

  &--on {
    background: var(--cm-ink);
    color: var(--cm-page);
  }

  &:focus-visible {
    outline: 2px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.rating-card__chart {
  position: relative;
  height: 130px;

  @media (min-width: $breakpoint-md-min) {
    height: 180px;
  }

  svg {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    overflow: visible;
  }
}

.rating-card__area {
  fill: var(--cm-brand-soft);
}

.rating-card__line {
  fill: none;
  stroke: var(--cm-brand);
  stroke-width: 2.5;
  stroke-linejoin: round;
  stroke-linecap: round;
}

.rating-card__dot {
  position: absolute;
  right: 0;
  width: 12px;
  height: 12px;
  margin: -6px -6px 0 0;
  border-radius: 50%;
  background: var(--cm-lime);
  border: 3px solid var(--cm-ink);
}

.rating-card__ticks {
  display: flex;
  justify-content: space-between;
  font-size: 11.5px;
  color: var(--cm-muted);
}

.rating-card__notice {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 130px;
  padding: 16px;
  border-radius: 16px;
  background: var(--cm-subtle);
  color: var(--cm-ink-soft);
  text-align: center;
}
</style>
