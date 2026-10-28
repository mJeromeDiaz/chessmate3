<template>
  <q-page class="shop" data-testid="shop">
    <div class="shop__inner">
      <header class="shop__head">
        <div class="shop__title">
          <div class="shop__lead">{{ $t('shop.page.lead') }}</div>
          <h1 class="shop__h1">{{ $t('shop.page.title') }}</h1>
        </div>
        <div
          :key="bump"
          class="shop__wallet"
          :class="{ 'shop__wallet--bump': bump > 0 }"
          data-testid="shop-balance"
          :aria-label="$t('shop.page.balance', { n: shown })"
        >
          <CrystalGem :size="32" spin />{{ crowns(shown) }}
        </div>
      </header>

      <p v-if="loadError" class="shop__error" role="alert">{{ loadError }}</p>

      <template v-if="state">
        <nav class="shop__tabs" :aria-label="$t('shop.page.tabs')">
          <button
            v-for="section in sections"
            :key="section.id"
            type="button"
            class="shop__tab"
            :class="{ 'shop__tab--on': tab === section.id }"
            :data-testid="`shop-tab-${section.id}`"
            @click="scrollTo(section.id)"
          >
            {{ section.tab }}
          </button>
        </nav>

        <ShopCarousel
          v-if="slides.length"
          :slides="slides"
          :paused="!!sheet || !!party"
          @open="open"
        />

        <div class="shop__sections">
          <section
            v-for="section in sections"
            :id="`shop-${section.id}`"
            :key="section.id"
            :ref="el => (refs[section.id] = el)"
            class="cm-card shop__section"
            :class="`shop__section--${section.id}`"
            :data-testid="`shop-section-${section.id}`"
          >
            <div class="shop__section-head">
              <h2 class="shop__h2">
                {{ section.title
                }}<span v-if="section.id === 'cosmetics'" class="shop__soon">{{
                  $t('shop.page.soon')
                }}</span>
              </h2>
              <span
                v-if="section.id === 'freeze' && streak > 0"
                class="shop__streak"
                >{{ $t('shop.page.toProtect', { n: streak }) }}</span
              >
            </div>
            <p class="shop__section-lead">{{ section.lead }}</p>
            <p
              v-if="section.id === 'freeze' && frozen > 0"
              class="shop__frozen"
              data-testid="shop-frozen"
            >
              {{ $t('shop.page.frozen', { n: frozen }, frozen > 1 ? 2 : 1) }}
            </p>

            <div v-if="section.id === 'freeze'" class="shop__grid">
              <OfferTile
                v-for="offer in offersIn(state.offers, 'freeze')"
                :key="offer.id"
                :offer="offer"
                :stock="stockOf(offer, state.inventory)"
                layout="card"
                @open="open"
              />
            </div>

            <div v-else-if="section.id === 'aids'" class="shop__list">
              <div class="shop__modules" role="tablist">
                <button
                  v-for="m in moduleTabs"
                  :key="m.id"
                  type="button"
                  role="tab"
                  class="shop__module"
                  :class="{ 'shop__module--on': module === m.id }"
                  :aria-selected="module === m.id"
                  :data-testid="`shop-module-${m.id}`"
                  @click="module = m.id"
                >
                  <span aria-hidden="true">{{ m.glyph }}</span
                  >{{ m.label }}
                </button>
              </div>
              <OfferTile
                v-for="offer in offersIn(state.offers, 'aids', module)"
                :key="offer.id"
                :offer="offer"
                :stock="stockOf(offer, state.inventory)"
                @open="open"
              />
            </div>

            <div v-else-if="section.id === 'packs'" class="shop__packs">
              <PackCard
                v-for="offer in offersIn(state.offers, 'packs')"
                :key="offer.id"
                :offer="offer"
                @open="open"
              />
            </div>

            <div v-else-if="section.id === 'wheel'" class="shop__list">
              <OfferTile
                v-for="offer in offersIn(state.offers, 'wheel')"
                :key="offer.id"
                :offer="offer"
                :stock="stockOf(offer, state.inventory)"
                @open="open"
              />
              <q-btn
                v-if="(state.inventory.wheel_ticket ?? 0) > 0"
                unelevated
                no-caps
                color="primary"
                class="shop__wheel-spin"
                :label="$t('shop.wheel.use')"
                data-testid="shop-wheel-spin"
                @click="shop.openTicketWheel()"
              />
            </div>

            <div v-else-if="section.id === 'cosmetics'" class="shop__cosmetics">
              <CosmeticCard
                v-for="offer in offersIn(state.offers, 'cosmetics')"
                :key="offer.id"
                :offer="offer"
              />
            </div>

            <div v-else class="shop__list">
              <OfferTile
                v-for="offer in offersIn(state.offers, section.id)"
                :key="offer.id"
                :offer="offer"
                :stock="stockOf(offer, state.inventory)"
                @open="open"
              />
            </div>
          </section>
        </div>

        <section
          v-if="arenaBosses.length"
          class="cm-card shop__section"
          data-testid="shop-section-arena"
        >
          <div class="shop__section-head">
            <h2 class="shop__h2">{{ $t('arena.shop.title') }}</h2>
            <router-link to="/arene" class="shop__arena-link">{{
              $t('arena.shop.see')
            }}</router-link>
          </div>
          <p class="shop__section-lead">{{ $t('arena.shop.lead') }}</p>
          <div class="shop__arena">
            <BossCard
              v-for="boss in arenaBosses"
              :id="boss.character"
              :key="boss.character"
              :name="bossText(boss.character, 'name')"
              :state="boss.status === 'shop' ? 'shop' : 'open'"
              :colors="paletteOf(boss.character)"
              v-bind="cardPicture(boss.character)"
              :sub="
                boss.status === 'shop'
                  ? $t('arena.card.elo', { elo: boss.elo })
                  : $t('arena.shop.owned')
              "
              :price="boss.price"
              @open="
                router.push({ path: '/arene', query: { boss: boss.character } })
              "
            />
          </div>
        </section>

        <section
          v-if="state.earning"
          class="cm-card shop__section shop__earn"
          data-testid="shop-earn"
        >
          <h2 class="shop__h2">{{ $t('shop.page.earnTitle') }}</h2>
          <p class="shop__section-lead">{{ $t('shop.page.earnLead') }}</p>
          <ul class="shop__earn-rows">
            <li v-for="row in earningRows(state.earning)" :key="row.label">
              <span>{{ row.label }}</span>
              <span class="shop__earn-value"
                ><CrystalGem :size="18" />{{
                  $t('shop.page.perHour', { n: crowns(row.value) })
                }}</span
              >
            </li>
          </ul>
          <p class="shop__earn-day" data-testid="shop-earn-left">
            {{
              $t('shop.page.daily', {
                n: state.earning.dailyMinutes,
                left:
                  state.earning.minutesLeftToday > 0
                    ? $t('shop.page.leftToday', {
                        n: state.earning.minutesLeftToday
                      })
                    : $t('shop.page.cappedToday')
              })
            }}
          </p>
          <q-btn
            color="primary"
            no-caps
            unelevated
            :label="$t('shop.page.launch')"
            to="/session"
          />
        </section>
      </template>
      <div v-else-if="!loadError" class="shop__wait">
        <q-spinner size="32px" color="primary" />
      </div>
    </div>

    <PurchaseSheet
      :offer="sheet"
      :balance="state?.balance ?? 0"
      :inventory="state?.inventory ?? {}"
      :busy="busy"
      :error="buyError"
      @close="sheet = null"
      @buy="buy"
    />
    <PurchaseCelebration
      v-if="party"
      :key="party.key"
      :offer="party.offer"
      :quantity="party.quantity"
      @done="party = null"
    />
  </q-page>
</template>

<script setup>
/**
 * The store (design "Boutique", docs/SHOP.md): the balance, the offers of the moment, the freezes,
 * Puzzle Streak items, module aids, packs, the gift wheel's tickets (spun from here), cosmetics to come, and how to earn crowns (§ 4 bis). A phone gets the design's single
 * column; from 1024 px, wider grids. A purchase is paid at once, then celebrated while the
 * balance counts down.
 */
import {
  computed,
  nextTick,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch
} from 'vue'
import { useRoute, useRouter } from 'vue-router'
import BossCard from '@/components/arena/BossCard.vue'
import CosmeticCard from '@/components/shop/CosmeticCard.vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import OfferTile from '@/components/shop/OfferTile.vue'
import PackCard from '@/components/shop/PackCard.vue'
import PurchaseCelebration from '@/components/shop/PurchaseCelebration.vue'
import PurchaseSheet from '@/components/shop/PurchaseSheet.vue'
import ShopCarousel from '@/components/shop/ShopCarousel.vue'
import { useGamificationStore } from '@/stores/gamification'
import { arenaApi } from '@/services/api'
import { useShopStore } from '@/stores/shop'
import { apiErrorMessage } from '@/utils/apiError'
import { bossText, cardPicture, paletteOf } from '@/utils/arena'
import { profImage } from '@/utils/prof/images'
import {
  MODULE_TABS,
  carouselSlides,
  SECTIONS,
  crowns,
  frozenDays,
  offersIn,
  stockOf,
  tweenAt
} from '@/utils/shop'
import { earningRows } from '@/utils/shopReward'
import { t } from '@/i18n'

definePage({
  meta: {
    auth: 'required',
    header: { title: 'shop.page.title', subtitle: 'layout.header.kickers.shop' }
  }
})

const shop = useShopStore()
const route = useRoute()
const router = useRouter()
const gamification = useGamificationStore()

const state = computed(() => shop.state)
const loadError = ref('')
/** @type {import('vue').Ref<import('@/utils/shop').ShopOffer|null>} */
const sheet = ref(null)
const busy = ref(false)
const buyError = ref('')
/** @type {import('vue').Ref<{key: number, offer: import('@/utils/shop').ShopOffer, quantity: number}|null>} */
const party = ref(null)
const module = ref('puzzles')
const tab = ref('freeze')
/** The balance shown: counts down after a purchase. */
const shown = ref(0)
/** Pops the wallet after a purchase. */
const bump = ref(0)
/** @type {Record<string, any>} */
const refs = reactive({})
/**
 * The arena's own bosses sold for crystals (docs/ARENA.md, § 6 bis), bought or not: bought on
 * their sheet in the arena.
 *
 * @type {import('vue').Ref<import('@/utils/arena').ArenaBoss[]>}
 */
const arenaBosses = ref([])

const sections = computed(() =>
  SECTIONS.filter(s => state.value?.offers.some(o => o.section === s.id))
)
const moduleTabs = computed(() =>
  MODULE_TABS.filter(m =>
    state.value?.offers.some(o => o.section === 'aids' && o.module === m.id)
  )
)
const slides = computed(() => carouselSlides(state.value, profImage))
const streak = computed(() => gamification.summary?.streak.current ?? 0)
const frozen = computed(() => frozenDays(state.value?.freezes ?? []))

watch(
  () => state.value?.balance,
  (balance, before) => {
    if (balance === undefined) return
    if (before === undefined) shown.value = balance
    else countDown(before, balance)
  },
  { immediate: true }
)

watch(moduleTabs, tabs => {
  if (tabs.length && !tabs.some(t => t.id === module.value))
    module.value = tabs[0].id
})

/** @type {ReturnType<typeof setInterval>|undefined} */
let tweener

/**
 * @param {number} from
 * @param {number} to
 */
function countDown(from, to) {
  clearInterval(tweener)
  let frame = 0
  // After the coins have flown.
  setTimeout(() => {
    tweener = setInterval(() => {
      frame += 1
      shown.value = tweenAt(from, to, frame / 24)
      if (frame >= 24) clearInterval(tweener)
    }, 30)
  }, 250)
}

/** @param {import('@/utils/shop').ShopOffer} offer */
function open(offer) {
  if (offer.comingSoon) return
  buyError.value = ''
  sheet.value = offer
}

/** @param {number} quantity */
async function buy(quantity) {
  const offer = sheet.value
  if (!offer) return
  busy.value = true
  buyError.value = ''
  try {
    await shop.buy(offer.id, quantity)
    sheet.value = null
    party.value = { key: Date.now(), offer, quantity }
    bump.value += 1
  } catch (e) {
    buyError.value = apiErrorMessage(e, {
      404: t('shop.errors.gone'),
      409: t('shop.errors.unavailable')
    })
    shop.load().catch(() => {})
  } finally {
    busy.value = false
  }
}

/** @param {string} id */
function scrollTo(id) {
  const el = refs[id]
  if (!el) return
  const top = el.getBoundingClientRect().top + window.scrollY - 110
  window.scrollTo({ top, behavior: 'smooth' })
}

/** The tab of the section under the tabs. */
function onScroll() {
  let current = sections.value[0]?.id ?? 'freeze'
  for (const section of sections.value) {
    const el = refs[section.id]
    if (el && el.getBoundingClientRect().top <= 140) current = section.id
  }
  const bottom =
    window.innerHeight + window.scrollY >=
    document.documentElement.scrollHeight - 4
  if (bottom && sections.value.length)
    current = sections.value[sections.value.length - 1].id
  tab.value = current
}

onMounted(async () => {
  window.addEventListener('scroll', onScroll, { passive: true })
  arenaApi
    .overview()
    .then(arena => {
      arenaBosses.value = arena.bosses.filter(boss => boss.price !== null)
    })
    .catch(() => {})
  if (!gamification.summary) gamification.load(['summary']).catch(() => {})
  try {
    await shop.load()
  } catch (e) {
    loadError.value = apiErrorMessage(e)
    return
  }
  // From the dashboard's carousel: that offer's sheet at once.
  const slug = route.query.offer
  const offer = state.value?.offers.find(o => o.slug === slug)
  if (offer) open(offer)
  // From the menu's wheel, none to spin: the wheel section (its ticket).
  const section = route.query.section
  if (typeof section === 'string' && sections.value.some(x => x.id === section))
    nextTick(() => scrollTo(section))
})

onBeforeUnmount(() => {
  window.removeEventListener('scroll', onScroll)
  clearInterval(tweener)
})
</script>

<style scoped lang="scss">
.shop__inner {
  max-width: 1120px;
  margin: 0 auto;
  padding: 12px 16px 48px;
  display: flex;
  flex-direction: column;
  gap: 14px;

  @media (min-width: $breakpoint-md-min) {
    padding: 24px 28px 64px;
    gap: 20px;
  }
}

.shop__head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.shop__title {
  flex: 1;
  min-width: 0;
}

.shop__lead {
  font-size: 12px;
  color: var(--cm-muted);
}

.shop__h1 {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 24px;
  line-height: 1.05;

  @media (min-width: $breakpoint-md-min) {
    font-size: 34px;
  }
}

.shop__wallet {
  height: 44px;
  padding: 0 14px 0 6px;
  border-radius: 16px;
  background: #1b1530;
  color: #c9b8ff;
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 800;
  font-size: 17px;
  font-variant-numeric: tabular-nums;

  &--bump {
    animation: shop-pop 0.5s cubic-bezier(0.34, 1.8, 0.64, 1) 0.9s both;
  }
}

.shop__tabs {
  position: sticky;
  top: 50px;
  z-index: 3;
  margin: 0 -16px;
  padding: 8px 16px;
  display: flex;
  gap: 6px;
  overflow-x: auto;
  background: var(--cm-page);
  scrollbar-width: none;

  @media (min-width: $breakpoint-md-min) {
    margin: 0;
    padding: 8px 0;
  }
}

.shop__tab {
  flex: none;
  height: 34px;
  padding: 0 13px;
  border: none;
  border-radius: 999px;
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;
  background: var(--cm-surface);
  color: var(--cm-ink);
  box-shadow: var(--cm-card-shadow);
  transition:
    background 0.2s,
    color 0.2s;

  &--on {
    background: var(--cm-ink);
    color: var(--cm-surface);
  }
}

.shop__sections {
  display: flex;
  flex-direction: column;
  gap: 22px;

  @media (min-width: $breakpoint-md-min) {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 32px 28px;
  }
}

.shop__section {
  display: flex;
  flex-direction: column;
  gap: 10px;
  scroll-margin-top: 110px;

  @media (min-width: $breakpoint-md-min) {
    &--freeze,
    &--packs,
    &--cosmetics {
      grid-column: 1 / -1;
    }
  }
}

.shop__earn {
  margin-top: 22px;
  align-items: flex-start;

  @media (min-width: $breakpoint-md-min) {
    margin-top: 32px;
  }
}

.shop__earn-rows {
  list-style: none;
  margin: 0;
  padding: 0 4px;
  width: 100%;
  display: flex;
  flex-direction: column;
  gap: 6px;

  li {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    font-weight: 600;
  }
}

.shop__earn-value {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 800;
  font-variant-numeric: tabular-nums;
}

.shop__earn-day {
  margin: 0;
  padding: 0 4px;
  color: var(--cm-muted);
  font-size: 13px;
  font-weight: 600;
}

.shop__section-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  padding: 0 4px;
}

.shop__h2 {
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 19px;
  line-height: 1.2;
}

.shop__soon {
  height: 22px;
  padding: 0 9px;
  border-radius: 999px;
  background: #1b1530;
  color: #c6f432;
  font-family: inherit;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.04em;
  display: flex;
  align-items: center;
}

.shop__streak {
  font-size: 12.5px;
  font-weight: 700;
  color: var(--cm-orange-ink);
}

.shop__arena {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 12px;
}

.shop__arena-link {
  margin-left: auto;
  color: var(--cm-brand);
  font-weight: 800;
  font-size: 13.5px;
}

.shop__section-lead,
.shop__frozen {
  margin: -4px 0 0;
  padding: 0 4px;
  font-size: 13px;
  line-height: 1.4;
  color: var(--cm-muted);
}

.shop__frozen {
  margin: 0;
  font-weight: 700;
  color: var(--cm-info);
}

.shop__grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
  }
}

.shop__list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.shop__wheel-spin {
  align-self: flex-start;
  min-height: 44px;
  padding: 0 20px;
  border-radius: 12px;
  font-weight: 800;
}

.shop__modules {
  display: flex;
  gap: 4px;
  padding: 4px;
  border-radius: 14px;
  background: var(--cm-subtle);
}

.shop__module {
  flex: 1;
  height: 36px;
  border: none;
  border-radius: 11px;
  cursor: pointer;
  font-weight: 700;
  font-size: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  background: transparent;
  color: var(--cm-muted);
  transition: background 0.2s;

  &--on {
    background: var(--cm-surface);
    color: var(--cm-ink);
    box-shadow: 0 1px 3px rgba(27, 21, 48, 0.12);
  }
}

.shop__packs {
  display: flex;
  flex-direction: column;
  gap: 10px;

  @media (min-width: $breakpoint-md-min) {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 14px;
  }
}

.shop__cosmetics {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 14px;
  }
}

.shop__wait {
  display: flex;
  justify-content: center;
  padding: 48px 0;
}

.shop__error {
  margin: 0;
  font-weight: 700;
  color: var(--cm-danger);
}

@keyframes shop-pop {
  0% {
    transform: scale(0.6);
  }
  55% {
    transform: scale(1.15);
  }
  100% {
    transform: none;
  }
}
</style>

<style lang="scss">
// The store's tiles (offers, packs), inside the section cards like the dashboard's rows: the
// inset tint, a pressable bottom edge.
.shop-tile {
  background: var(--cm-inset);
  // A button to press: a marked bottom edge, sunk on press.
  box-shadow:
    0 0 0 1.5px var(--cm-dash),
    0 5px 0 #cbc4e2;
  transition:
    background 0.15s,
    transform 0.1s;

  &:hover {
    background: var(--cm-subtle);
  }

  &:active {
    transform: translateY(5px);
    box-shadow: 0 0 0 1.5px var(--cm-dash);
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

// As the dashboard's rows: the tint alone on the dark surface.
.body--dark .shop-tile,
.body--dark .shop-tile:active {
  box-shadow: none;
}

.body--dark .shop__section {
  box-shadow: none;
}
</style>
