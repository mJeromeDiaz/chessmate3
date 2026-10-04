<template>
  <q-page v-if="auth.isAuthenticated" class="dashboard" data-testid="dashboard">
    <div class="dashboard__inner">
      <header class="dashboard__head">
        <div>
          <div class="dashboard__hello lt-md">Bonjour 👋&#xFE0E;</div>
          <h1 class="dashboard__title">Ta progression</h1>
        </div>
        <div class="dashboard__head-actions">
          <span v-if="!store.isNewUser" class="dashboard__streak"
            >🔥&#xFE0E; {{ SHOWCASE.streak }} jours <ShowcaseTag
          /></span>
          <q-btn
            unelevated
            no-caps
            color="dark"
            class="dashboard__new"
            to="/session/new"
            label="+ Nouvelle session"
            data-testid="dashboard-new-session"
          />
        </div>
      </header>

      <div v-if="store.loading && !store.loaded" class="dashboard__grid">
        <div class="dashboard__col dashboard__col--main">
          <q-skeleton height="150px" class="dashboard__skeleton" />
          <q-skeleton height="260px" class="dashboard__skeleton" />
        </div>
        <div class="dashboard__col">
          <q-skeleton height="220px" class="dashboard__skeleton" />
          <q-skeleton height="160px" class="dashboard__skeleton" />
        </div>
      </div>

      <template v-else-if="store.isNewUser">
        <WelcomeCard />
        <!-- A first session launched but no exercise logged yet: it can still be resumed. -->
        <MyPlans hide-empty class="q-mt-md" />
        <RecentSessions hide-empty class="q-mt-md" />
        <ModuleProgress :rows="moduleRows" class="q-mt-md" />
      </template>

      <div v-else class="dashboard__grid">
        <div class="dashboard__col dashboard__col--main">
          <LevelBanner class="dashboard__banner" />
          <RatingCard class="dashboard__rating" />
          <ModuleProgress :rows="moduleRows" class="dashboard__modules" />
        </div>
        <div class="dashboard__col">
          <ActivityHeatmap
            v-if="store.activity"
            :today="store.activity.today"
            :days="store.activity.days"
            class="dashboard__heat"
          />
          <div
            v-else-if="store.errors.activity"
            class="cm-card dashboard__heat"
          >
            <h2 class="cm-card__title">Régularité</h2>
            <p class="cm-muted q-mt-sm q-mb-none">{{
              store.errors.activity
            }}</p>
          </div>
          <WeeklyQuest class="dashboard__quest" />
          <MyPlans class="dashboard__sessions" />
          <RecentSessions class="dashboard__sessions" />
          <TrophyGrid class="dashboard__trophies" />
        </div>
      </div>

      <q-banner
        v-if="sectionError"
        rounded
        class="dashboard__error q-mt-md"
        data-testid="dashboard-error"
      >
        {{ sectionError }}
        <template #action>
          <q-btn
            flat
            no-caps
            color="primary"
            label="Réessayer"
            @click="store.load()"
          />
        </template>
      </q-banner>
    </div>
  </q-page>

  <LandingPage v-else />
</template>

<script setup>
import { computed, onMounted, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useDashboardStore } from '@/stores/dashboard'
import { buildModuleRows } from '@/utils/dashboard/modules'
import { SHOWCASE } from '@/utils/dashboard/showcase'
import ActivityHeatmap from '@/components/dashboard/ActivityHeatmap.vue'
import LevelBanner from '@/components/dashboard/LevelBanner.vue'
import ModuleProgress from '@/components/dashboard/ModuleProgress.vue'
import RatingCard from '@/components/dashboard/RatingCard.vue'
import MyPlans from '@/components/dashboard/MyPlans.vue'
import RecentSessions from '@/components/dashboard/RecentSessions.vue'
import ShowcaseTag from '@/components/dashboard/ShowcaseTag.vue'
import TrophyGrid from '@/components/dashboard/TrophyGrid.vue'
import WeeklyQuest from '@/components/dashboard/WeeklyQuest.vue'
import WelcomeCard from '@/components/dashboard/WelcomeCard.vue'
import LandingPage from '@/components/landing/LandingPage.vue'

/**
 * Home: the dashboard once signed in (design "Dashboard", docs/DASHBOARD.md), the landing page
 * otherwise (design "Landing": the layout then hides its header, see `meta.landing`).
 */
definePage({ meta: { landing: true } })

const auth = useAuthStore()
const store = useDashboardStore()

const moduleRows = computed(() =>
  buildModuleRows({
    totals: store.activity?.totals ?? {},
    puzzleRating: store.puzzleRating,
    sets: store.sets,
    repertoires: store.repertoires
  })
)

/** Module figures that could not be loaded (their row then reads as empty). */
const sectionError = computed(() =>
  ['puzzle', 'woodpecker', 'repertoire'].some(s => store.errors[s])
    ? 'Certaines statistiques des modules n’ont pas pu être chargées.'
    : ''
)

onMounted(() => {
  if (auth.isAuthenticated) store.load()
})

watch(
  () => auth.isAuthenticated,
  signedIn => {
    if (signedIn) store.load()
  }
)
</script>

<style scoped lang="scss">
.dashboard__inner {
  max-width: 1180px;
  margin: 0 auto;
  padding: 12px 16px 48px;

  @media (min-width: $breakpoint-md-min) {
    padding: 24px 28px 48px;
  }
}

.dashboard__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;

  @media (min-width: $breakpoint-md-min) {
    margin-bottom: 18px;
  }
}

.dashboard__hello {
  font-size: 13px;
  color: var(--cm-muted);
}

.dashboard__title {
  margin: 0;
  font-size: 22px;
  line-height: 1.1;

  @media (min-width: $breakpoint-md-min) {
    font-size: 28px;
  }
}

.dashboard__head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.dashboard__streak {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 40px;
  padding: 0 12px;
  border-radius: 14px;
  background: var(--cm-orange-soft);
  color: var(--cm-orange-ink);
  font-weight: 800;
  font-size: 15px;
}

.dashboard__new {
  height: 40px;
  border-radius: 14px;

  @media (max-width: $breakpoint-xs-max) {
    display: none;
  }
}

// Mobile: one column in the design's order; from md: two columns (1.55 / 1).
.dashboard__grid {
  display: flex;
  flex-direction: column;
  gap: 14px;

  @media (min-width: $breakpoint-md-min) {
    display: grid;
    grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr);
    gap: 18px;
    align-items: start;
  }
}

.dashboard__col {
  display: contents;

  @media (min-width: $breakpoint-md-min) {
    display: flex;
    flex-direction: column;
    gap: 18px;
    min-width: 0;
  }
}

.dashboard__banner {
  order: 1;
}
.dashboard__rating {
  order: 2;
}
.dashboard__heat {
  order: 3;
}
.dashboard__quest {
  order: 4;
}
.dashboard__modules {
  order: 5;
}
.dashboard__sessions {
  order: 6;
}
.dashboard__trophies {
  order: 7;
}

.dashboard__skeleton {
  border-radius: 24px;
}

.dashboard__error {
  background: var(--cm-danger-soft);
  color: var(--cm-danger);
}
</style>
