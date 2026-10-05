<template>
  <q-page class="stats" data-testid="stats-page">
    <div class="stats__inner">
      <header class="stats__head">
        <div class="row items-center no-wrap">
          <q-btn
            flat
            round
            dense
            icon="arrow_back"
            to="/"
            aria-label="Accueil"
          />
          <h1 class="stats__title">Mes statistiques</h1>
        </div>
        <PeriodPicker
          :model-value="store.period"
          @update:model-value="store.loadStats"
        />
      </header>

      <div class="stats__grid">
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
            title="Temps d’entraînement"
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

        <div>
          <q-skeleton
            v-if="!store.themes && store.statsLoading"
            height="260px"
            class="stats__skeleton"
          />
          <ThemeStrengthsCard v-else-if="store.themes" :themes="store.themes" />
          <SectionError
            v-else-if="store.statsErrors.themes"
            title="Thèmes de puzzles"
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
            title="Répertoires"
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
import RepertoireHealthCard from '@/components/dashboard/RepertoireHealthCard.vue'
import SectionError from '@/components/dashboard/SectionError.vue'
import SessionSummary from '@/components/dashboard/SessionSummary.vue'
import ThemeStrengthsCard from '@/components/dashboard/ThemeStrengthsCard.vue'
import TrainingTimeChart from '@/components/dashboard/TrainingTimeChart.vue'

/**
 * Statistics (docs/DASHBOARD.md § 6, lot B): training time by week and module, sessions, strong
 * and weak puzzle themes, repertoire health, over a period remembered in this browser. Reached
 * from the home page (the main menu is unchanged). Each block loads and fails on its own.
 */
definePage({ meta: { auth: 'required' } })

const store = useDashboardStore()

onMounted(() => store.loadStats())
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
