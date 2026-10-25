<template>
  <q-page v-if="auth.isAuthenticated" class="dashboard" data-testid="dashboard">
    <div class="dashboard__inner">
      <header class="dashboard__head">
        <div>
          <div class="dashboard__hello lt-md">{{
            $t('dashboard.home.hello')
          }}</div>
          <h1 class="dashboard__title">{{ $t('dashboard.home.title') }}</h1>
        </div>
        <div class="dashboard__head-actions">
          <q-btn
            unelevated
            no-caps
            class="dashboard__new cm-btn-create"
            to="/session/new"
            :label="$t('dashboard.home.newSession')"
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
        <PathCard v-if="store.pathsLoaded" class="q-mt-md" />
        <ModuleProgress :rows="moduleRows" class="q-mt-md" />
      </template>

      <!-- Design « Dashboard v2 »: the order of the phone's column, two columns from md. -->
      <div v-else class="dashboard__grid">
        <div class="dashboard__col dashboard__col--main">
          <div class="dashboard__hero">
            <LevelBanner class="dashboard__banner" />
            <StatsLink class="dashboard__stats" />
          </div>
          <PathCard v-if="store.pathsLoaded" class="dashboard__path" />
          <div class="dashboard__pair">
            <EndgameCard class="dashboard__endgames" />
            <ChallengesCard class="dashboard__challenges" />
          </div>
          <ModuleProgress :rows="moduleRows" class="dashboard__modules" />
        </div>
        <div class="dashboard__col">
          <StreakCard class="dashboard__streak-card" />
          <MyPlans class="dashboard__sessions" />
          <WeeklyQuest class="dashboard__quest" />
          <ShopDeals class="dashboard__shop" />
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
            :label="$t('common.actions.retry')"
            @click="store.load()"
          />
        </template>
      </q-banner>
    </div>
    <TreasureChest
      v-if="chest"
      :key="chest.id"
      :reward="chest"
      :results-label="$t('training.page.seeReview')"
      @results="seeSession(chest)"
      @close="dismissChest(chest)"
      @shop="dismissChest(chest)"
    />
  </q-page>

  <LandingPage v-else />
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { shopApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { useDashboardStore } from '@/stores/dashboard'
import { buildModuleRows } from '@/utils/dashboard/modules'
import { useGamificationStore } from '@/stores/gamification'
import { useShopStore } from '@/stores/shop'
import TreasureChest from '@/components/shop/TreasureChest.vue'
import ChallengesCard from '@/components/dashboard/ChallengesCard.vue'
import EndgameCard from '@/components/dashboard/EndgameCard.vue'
import LevelBanner from '@/components/dashboard/LevelBanner.vue'
import ModuleProgress from '@/components/dashboard/ModuleProgress.vue'
import MyPlans from '@/components/dashboard/MyPlans.vue'
import PathCard from '@/components/dashboard/PathCard.vue'
import RecentSessions from '@/components/dashboard/RecentSessions.vue'
import ShopDeals from '@/components/dashboard/ShopDeals.vue'
import StatsLink from '@/components/dashboard/StatsLink.vue'
import StreakCard from '@/components/dashboard/StreakCard.vue'
import TrophyGrid from '@/components/dashboard/TrophyGrid.vue'
import WeeklyQuest from '@/components/dashboard/WeeklyQuest.vue'
import WelcomeCard from '@/components/dashboard/WelcomeCard.vue'
import LandingPage from '@/components/landing/LandingPage.vue'
import { t } from '@/i18n'

/**
 * Home: the dashboard once signed in (design « Dashboard v2 », docs/DASHBOARD.md), the landing page
 * otherwise (design "Landing": the layout then hides its header, see `meta.landing`).
 */
definePage({ meta: { landing: true } })

const auth = useAuthStore()
const store = useDashboardStore()
const gamification = useGamificationStore()
const shop = useShopStore()
const router = useRouter()

/**
 * The chests not seen yet (docs/SHOP.md, § 4 bis): a run page left before its chest, another
 * device… none is missed. Shown one after the other, oldest first, after the streak's celebration.
 *
 * @type {import('vue').Ref<{id: string, crowns: number, paidMinutes: number, tier: string, capped: boolean, opened: boolean, sessionId: string}[]>}
 */
const chests = ref([])
const chest = computed(() => chests.value[0] ?? null)

/** @param {{id: string}} seen */
function dismissChest(seen) {
  chests.value = chests.value.filter(c => c.id !== seen.id)
}

/** "Voir le bilan": the session it came from. @param {{id: string, sessionId: string}} seen */
function seeSession(seen) {
  dismissChest(seen)
  router.push(`/session/${seen.sessionId}`)
}

const moduleRows = computed(() =>
  buildModuleRows({
    totals: store.activity?.totals ?? {},
    puzzleRating: store.puzzleRating,
    sets: store.sets,
    repertoires: store.repertoires,
    gamification: gamification.summary
  })
)

/** Module figures that could not be loaded (their row then reads as empty; activity: the totals). */
const sectionError = computed(() =>
  ['activity', 'puzzle', 'woodpecker', 'repertoire'].some(s => store.errors[s])
    ? t('dashboard.home.sectionError')
    : ''
)

/** Loads the dashboard; an announcement left unseen (a run closed later, another tab) shows. */
function loadAll() {
  store.load()
  store.loadPaths()
  const unopened = shopApi.unopenedRewards().catch(() => [])
  gamification
    .load()
    .then(() => gamification.celebrateStreak({ afterExercise: false }))
    .catch(() => {})
    .then(() => unopened)
    // Those already seen in this tab (the server may not know yet) are left out; one opened here
    // stays on screen until left.
    .then(list => (chests.value = list.filter(c => !shop.seenChests.has(c.id))))
}

onMounted(() => {
  if (auth.isAuthenticated) loadAll()
})

watch(
  () => auth.isAuthenticated,
  signedIn => {
    if (signedIn) loadAll()
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

.dashboard__new {
  height: 40px;
  border-radius: 14px;

  @media (max-width: $breakpoint-xs-max) {
    display: none;
  }
}

// Mobile: one column in the design's order; from md: a main column and a 400 px side one.
.dashboard__grid {
  display: flex;
  flex-direction: column;
  gap: 14px;

  @media (min-width: $breakpoint-md-min) {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 400px;
    gap: 22px;
    align-items: start;
  }
}

.dashboard__col,
.dashboard__hero,
.dashboard__pair {
  display: contents;
}

@media (min-width: $breakpoint-md-min) {
  .dashboard__col {
    display: flex;
    flex-direction: column;
    gap: 22px;
    min-width: 0;
  }

  .dashboard__hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 250px;
    gap: 16px;
  }

  .dashboard__pair {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 22px;
    align-items: start;
  }
}

// Phone: the design's order (stats, level, streak, sessions, quest, paths, levels, store, trophies).
@media (max-width: $breakpoint-sm-max) {
  .dashboard__stats {
    order: 1;
  }
  .dashboard__banner {
    order: 2;
  }
  .dashboard__streak-card {
    order: 3;
  }
  .dashboard__sessions {
    order: 4;
  }
  .dashboard__quest {
    order: 5;
  }
  .dashboard__path {
    order: 6;
  }
  .dashboard__endgames {
    order: 7;
  }
  .dashboard__challenges {
    order: 8;
  }
  .dashboard__modules {
    order: 9;
  }
  .dashboard__shop {
    order: 10;
  }
  .dashboard__trophies {
    order: 11;
  }
}

.dashboard__skeleton {
  border-radius: 24px;
}

.dashboard__error {
  background: var(--cm-danger-soft);
  color: var(--cm-danger);
}
</style>
