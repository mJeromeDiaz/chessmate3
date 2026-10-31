<template>
  <q-page class="arena-page" data-testid="arena">
    <div class="arena-page__inner">
      <p v-if="error" class="arena-page__error" role="alert">{{ error }}</p>
      <div v-else-if="!overview" class="arena-page__wait">
        <q-spinner size="2em" />
        <span>{{ $t('arena.page.loading') }}</span>
      </div>

      <template v-else>
        <router-link
          v-if="overview.currentGame"
          to="/arene/partie"
          class="arena-page__resume"
          data-testid="arena-resume"
        >
          <q-icon name="play_arrow" size="22px" />
          <span>{{ $t('arena.page.resume') }}</span>
        </router-link>

        <section
          v-for="group in levels"
          :key="group.level"
          class="arena-page__level"
          :class="{
            'arena-page__level--masters': group.level === MASTERS_LEVEL
          }"
          :data-testid="`arena-level-${group.level}`"
        >
          <header class="arena-page__level-head">
            <span class="arena-page__level-n">{{
              $t('arena.level', { n: group.level })
            }}</span>
            <h2 class="arena-page__level-name">{{ levelName(group.level) }}</h2>
            <span class="arena-page__level-meta">{{
              $t('arena.page.levelMeta', {
                beaten: group.beaten,
                total: group.bosses.length
              })
            }}</span>
          </header>
          <p
            v-if="group.level === MASTERS_LEVEL"
            class="arena-page__level-lead"
          >
            {{ $t('arena.masters.lead') }}
          </p>
          <div class="arena-page__grid">
            <BossCard
              v-for="boss in group.bosses"
              :id="boss.character"
              :key="boss.character"
              :name="displayName(boss)"
              :state="cardState(boss)"
              :colors="paletteOf(boss.character)"
              v-bind="cardPicture(boss.character)"
              :mystery="isMystery(boss)"
              :sub="subOf(boss)"
              :price="boss.price"
              :progress="boss.status === 'locked' ? featProgress(boss) : null"
              @open="open(boss.character)"
            />
          </div>
        </section>
      </template>
    </div>

    <BossSheet
      :boss="selected"
      :cadences="overview?.cadences ?? []"
      :default-cadence="overview?.defaultCadence ?? ''"
      :balance="overview?.balance ?? 0"
      :busy="busy"
      :error="sheetError"
      @close="close"
      @buy="buy"
      @play="play"
    />
  </q-page>
</template>

<script setup>
/**
 * The arena (docs/ARENA.md, design « Défier les boss »): the bosses by level, each one's card and
 * sheet; a boss of the arena bought in place from its sheet, then its arrival to show. The last
 * level, the professors', each one brought by a feat in their module (§ 2 bis), Ray a mystery until
 * then. `?boss=<character>` opens a boss's sheet (the shop's links).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BossCard from '@/components/arena/BossCard.vue'
import BossSheet from '@/components/arena/BossSheet.vue'
import { usePageHeader } from '@/composables/layout/usePageHeader'
import { t } from '@/i18n'
import { arenaApi } from '@/services/api'
import { useArenaStore } from '@/stores/arena'
import { useShopStore } from '@/stores/shop'
import { apiErrorMessage } from '@/utils/apiError'
import {
  MASTERS_LEVEL,
  beatenCount,
  cardPicture,
  cardState,
  displayName,
  featProgress,
  isMaster,
  isMystery,
  levelName,
  levelsOf,
  paletteOf
} from '@/utils/arena'

definePage({
  meta: {
    auth: 'required',
    // Open to a guest (docs/GUEST.md).
    guestOpen: true,
    header: { title: 'arena.page.title' }
  }
})

const route = useRoute()
const router = useRouter()
const shop = useShopStore()
const arenaStore = useArenaStore()

/** @type {import('vue').Ref<import('@/utils/arena').ArenaOverview|null>} */
const overview = ref(null)
const error = ref('')
/** The boss whose sheet is open. */
const sheet = ref(/** @type {string|null} */ (null))
const busy = ref(false)
const sheetError = ref('')

const bosses = computed(() => overview.value?.bosses ?? [])
const levels = computed(() => levelsOf(bosses.value))
const selected = computed(
  () => bosses.value.find(boss => boss.character === sheet.value) ?? null
)

usePageHeader(() => ({
  title: t('arena.page.title'),
  subtitle: overview.value
    ? t(
        'arena.page.beaten',
        { n: beatenCount(bosses.value), total: bosses.value.length },
        beatenCount(bosses.value)
      )
    : null
}))

watch(
  () => route.query.boss,
  boss => {
    if (typeof boss === 'string' && boss) open(boss)
  },
  { immediate: true }
)

onMounted(load)

async function load() {
  try {
    overview.value = await arenaApi.overview()
    arenaStore.fromOverview(overview.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

/**
 * Its card's second line.
 *
 * @param {import('@/utils/arena').ArenaBoss} boss
 */
function subOf(boss) {
  const state = cardState(boss)
  if (state === 'beaten') return t('arena.card.beaten')
  if (state === 'open') return t('arena.card.elo', { elo: boss.elo })
  if (state === 'locked' && isMaster(boss) && boss.feat)
    return t(`arena.feat.short.${boss.character}`, {
      current: boss.feat.current,
      goal: boss.feat.goal
    })
  return t(`arena.card.${state}`)
}

/** @param {string} character */
function open(character) {
  sheetError.value = ''
  sheet.value = character
}

function close() {
  sheet.value = null
  if (route.query.boss) router.replace({ query: {} })
}

/** Buys the boss of the sheet; the menus get the new balance. */
async function buy() {
  const boss = selected.value
  if (!boss || busy.value) return
  busy.value = true
  sheetError.value = ''
  try {
    overview.value = await arenaApi.purchase(boss.character)
    // The boss bought arrives at once, over the page: its sheet closes (« Le défier » opens it
    // again, through `?boss=`).
    close()
    arenaStore.fromOverview(overview.value)
    if (shop.state) shop.load().catch(() => {})
  } catch (e) {
    sheetError.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

/**
 * Starts a game against the boss of the sheet (the game screen, A2b).
 *
 * @param {{color: string, cadence: string}} settings
 */
function play(settings) {
  const boss = selected.value
  if (!boss) return
  router.push({
    path: '/arene/partie',
    query: { boss: boss.character, ...settings }
  })
}
</script>

<style scoped lang="scss">
.arena-page {
  background: var(--cm-page);
}

.arena-page__inner {
  max-width: 1200px;
  margin: 0 auto;
  padding: 8px 0 32px;
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.arena-page__error {
  margin: 16px;
  color: var(--cm-danger);
  font-weight: 700;
}

.arena-page__wait {
  padding: 48px 16px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  color: var(--cm-muted);
  font-weight: 700;
}

.arena-page__resume {
  margin: 0 16px;
  min-height: 52px;
  border-radius: 16px;
  background: var(--cm-brand);
  color: #fff;
  font-weight: 800;
  font-size: 15px;
  text-decoration: none;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 0 16px;
}

.arena-page__level {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.arena-page__level-head {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding: 0 20px;
}

.arena-page__level-n {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  color: var(--cm-brand);
  white-space: nowrap;
}

.arena-page__level-name {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 19px;
  line-height: 1.2;
}

.arena-page__level-meta {
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-muted);
  white-space: nowrap;
}

.arena-page__level-lead {
  margin: 0;
  padding: 0 20px;
  font-size: 13.5px;
  color: var(--cm-muted);
}

.arena-page__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  padding: 0 16px;
}

@media (min-width: 600px) {
  .arena-page__grid {
    grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  }
}

@media (min-width: 1024px) {
  .arena-page__inner {
    padding: 12px 24px 40px;
    gap: 34px;
  }
  .arena-page__level {
    gap: 12px;
  }
  .arena-page__resume {
    margin: 0;
    align-self: flex-start;
  }
  .arena-page__level-head,
  .arena-page__level-lead,
  .arena-page__grid {
    padding: 0;
  }
  .arena-page__level-n {
    font-size: 12px;
  }
  .arena-page__level-name {
    font-size: 22px;
  }
  .arena-page__grid {
    gap: 16px;
  }
}
</style>
