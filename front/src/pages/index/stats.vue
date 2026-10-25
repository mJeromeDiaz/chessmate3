<template>
  <q-page class="stats" data-testid="stats-page">
    <div class="stats__inner">
      <header class="stats__head">
        <h1 class="stats__title">{{ $t('dashboard.stats.title') }}</h1>
        <PeriodPicker
          :model-value="store.period"
          @update:model-value="store.loadStats"
        />
      </header>

      <div class="stats__grid">
        <!-- The rating curves left the home page for here (design « Dashboard v2 »). -->
        <div class="stats__wide">
          <RatingCard />
        </div>

        <div class="stats__wide">
          <q-skeleton
            v-if="!store.training && store.statsLoading"
            height="300px"
            class="stats__skeleton"
          />
          <TrainingTimeChart
            v-else-if="store.training"
            :training="store.training"
          />
          <SectionError
            v-else-if="store.statsErrors.training"
            :title="$t('dashboard.time.title')"
            :message="store.statsErrors.training"
            @retry="store.loadStats()"
          />
        </div>

        <div class="stats__wide">
          <SessionSummary
            v-if="store.training"
            :sessions="store.training.sessions"
          />
        </div>

        <div class="stats__wide">
          <RecentSessions />
        </div>

        <div>
          <q-skeleton
            v-if="!store.themes && store.statsLoading"
            height="260px"
            class="stats__skeleton"
          />
          <ThemeStrengthsCard v-else-if="store.themes" :themes="store.themes" />
          <SectionError
            v-else-if="store.statsErrors.themes"
            :title="$t('dashboard.themes.title')"
            :message="store.statsErrors.themes"
            @retry="store.loadStats()"
          />
        </div>

        <div>
          <q-skeleton
            v-if="!store.health && store.statsLoading"
            height="260px"
            class="stats__skeleton"
          />
          <RepertoireHealthCard
            v-else-if="store.health"
            :health="store.health"
          />
          <SectionError
            v-else-if="store.statsErrors.repertoire"
            :title="$t('dashboard.health.title')"
            :message="store.statsErrors.repertoire"
            @retry="store.loadStats()"
          />
        </div>
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { onMounted } from 'vue'
import { useDashboardStore } from '@/stores/dashboard'
import PeriodPicker from '@/components/dashboard/PeriodPicker.vue'
import RatingCard from '@/components/dashboard/RatingCard.vue'
import RecentSessions from '@/components/dashboard/RecentSessions.vue'
import RepertoireHealthCard from '@/components/dashboard/RepertoireHealthCard.vue'
import SectionError from '@/components/dashboard/SectionError.vue'
import SessionSummary from '@/components/dashboard/SessionSummary.vue'
import ThemeStrengthsCard from '@/components/dashboard/ThemeStrengthsCard.vue'
import TrainingTimeChart from '@/components/dashboard/TrainingTimeChart.vue'

/**
 * Statistics (docs/DASHBOARD.md § 3 bis): the puzzle and Lichess rating curves (90 days), then
 * training time by week and module, sessions and the latest ones, strong and weak puzzle themes,
 * repertoire health, over a period remembered in this browser. Reached from the home page. Each
 * block loads and fails on its own.
 */
definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'dashboard.stats.title',
      subtitle: 'layout.header.kickers.dashboard',
      parent: '/'
    }
  }
})

const store = useDashboardStore()

onMounted(() => {
  store.loadStats()
  if (!store.rating) store.loadRating()
})
</script>

<style scoped lang="scss">
.stats__inner {
  max-width: 1180px;
  margin: 0 auto;
  padding: 12px 16px 48px;

  @media (min-width: $breakpoint-md-min) {
    padding: 24px 28px 48px;
  }
}

.stats__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 16px;
}

.stats__title {
  margin: 0 0 0 8px;
  font-size: 22px;
  line-height: 1.1;

  @media (min-width: $breakpoint-md-min) {
    font-size: 28px;
  }
}

.stats__grid {
  display: grid;
  gap: 12px;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
  }
}

.stats__wide {
  @media (min-width: $breakpoint-md-min) {
    grid-column: 1 / -1;
  }

  &:empty {
    display: none;
  }
}

.stats__skeleton {
  border-radius: 22px;
}
</style>
