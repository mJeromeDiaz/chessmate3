<template>
  <q-page class="guest-home" data-testid="guest-home">
    <div class="guest-home__inner">
      <section class="guest-home__banner cm-card" data-testid="guest-banner">
        <div class="guest-home__banner-text">
          <div class="guest-home__kicker">{{ $t('guest.home.kicker') }}</div>
          <h1 class="guest-home__title cm-heading">{{
            $t('guest.home.title')
          }}</h1>
          <p class="guest-home__lead">{{ $t('guest.home.lead') }}</p>
        </div>
        <router-link
          to="/creer-un-compte"
          class="guest-home__cta"
          data-testid="guest-home-account"
          >{{ $t('guest.header') }}</router-link
        >
      </section>

      <div class="guest-home__grid">
        <div class="guest-home__col">
          <h2 class="guest-home__h2">{{ $t('guest.home.paths') }}</h2>
          <PathCard v-if="store.pathsLoaded" />
          <div class="guest-home__pair">
            <EndgameCard />
            <ChallengesCard />
          </div>
        </div>

        <div class="guest-home__col">
          <h2 class="guest-home__h2">{{ $t('guest.home.modules') }}</h2>
          <div class="guest-home__tiles">
            <router-link
              v-for="item in open"
              :key="item.id"
              :to="{ path: item.to, query: item.query }"
              class="guest-home__tile cm-card"
              :data-testid="`guest-module-${item.id}`"
            >
              <q-icon :name="item.icon" size="22px" />
              <span>{{ item.label }}</span>
            </router-link>
          </div>

          <h2 class="guest-home__h2">{{ $t('guest.home.locked') }}</h2>
          <div class="guest-home__tiles">
            <router-link
              v-for="item in locked"
              :key="item.id"
              to="/creer-un-compte"
              class="guest-home__tile guest-home__tile--locked cm-card"
              :data-testid="`guest-locked-${item.id}`"
            >
              <q-icon :name="item.icon" size="22px" />
              <span>{{ item.label }}</span>
              <q-icon name="lock" size="16px" class="guest-home__lock" />
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import ChallengesCard from '@/components/dashboard/ChallengesCard.vue'
import EndgameCard from '@/components/dashboard/EndgameCard.vue'
import PathCard from '@/components/dashboard/PathCard.vue'
import { t } from '@/i18n'
import { useDashboardStore } from '@/stores/dashboard'
import { FREE_TRAINING_LINKS } from '@/utils/layout/navigation'

/**
 * A guest's home (docs/GUEST.md): the sign-up first, the paths (their first chapter as a demo),
 * the modules it may play and the arena, then what an account opens, locked. Only the paths are
 * read: the dashboard's other figures are an account's.
 */
const router = useRouter()
const store = useDashboardStore()

/** The arena, open to a guest (it costs nothing). */
const ARENA = {
  id: 'arena',
  to: '/arene',
  icon: 'sports_martial_arts',
  get label() {
    return t('layout.menu.arena.title')
  }
}

/** What an account opens beyond the free modules. */
const ACCOUNT_LINKS = [
  {
    id: 'sessions',
    to: '/session',
    icon: 'playlist_add',
    get label() {
      return t('layout.menu.create.title')
    }
  },
  {
    id: 'shop',
    to: '/boutique',
    icon: 'diamond',
    get label() {
      return t('layout.menu.shop.title')
    }
  }
]

/**
 * @param {{to: string}} item
 */
const isOpen = item => !!router.resolve(item.to).meta?.guestOpen

const open = computed(() => [...FREE_TRAINING_LINKS.filter(isOpen), ARENA])
const locked = computed(() => [
  ...FREE_TRAINING_LINKS.filter(item => !isOpen(item)),
  ...ACCOUNT_LINKS
])

onMounted(() => {
  store.loadPaths().catch(() => {})
})
</script>

<style scoped lang="scss">
.guest-home__inner {
  max-width: 1200px;
  margin: 0 auto;
  padding: 12px 16px 48px;
  display: flex;
  flex-direction: column;
  gap: 20px;

  @media (min-width: $breakpoint-md-min) {
    padding: 24px 28px 48px;
  }
}

.guest-home__banner {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 14px 24px;
  padding: 20px;
  background: var(--cm-brand-soft);
}

.guest-home__banner-text {
  flex: 1 1 320px;
  min-width: 0;
}

.guest-home__kicker {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: var(--cm-brand-deep);
}

.guest-home__title {
  margin: 4px 0 6px;
  font-size: 26px;
  line-height: 1.1;
}

.guest-home__lead {
  margin: 0;
  font-size: 15px;
  line-height: 1.5;
  color: var(--cm-ink-soft);
  text-wrap: pretty;
}

.guest-home__cta {
  flex: none;
  height: 50px;
  padding: 0 20px;
  border-radius: 16px;
  background: #1b1530;
  color: #fff;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;

  &:hover {
    color: #fff;
  }

  // The page's ink is light in dark mode: the button keeps a visible edge.
  body.body--dark & {
    box-shadow: inset 0 0 0 1px var(--cm-line);
  }

  @media (max-width: $breakpoint-xs-max) {
    flex-basis: 100%;
  }
}

.guest-home__grid {
  display: grid;
  gap: 20px;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr);
    align-items: start;
  }
}

.guest-home__col {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-width: 0;
}

.guest-home__h2 {
  margin: 4px 0 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
}

.guest-home__pair {
  display: grid;
  gap: 12px;

  @media (min-width: $breakpoint-sm-min) {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

.guest-home__tiles {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
}

.guest-home__tile {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px;
  font-weight: 700;
  font-size: 14px;
  color: var(--cm-ink);
  text-decoration: none;

  &--locked {
    color: var(--cm-muted);
  }
}

.guest-home__lock {
  margin-left: auto;
}
</style>
