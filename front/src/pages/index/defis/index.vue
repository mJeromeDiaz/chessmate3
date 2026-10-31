<template>
  <q-page padding>
    <div class="challenge-path" data-testid="challenge-path">
      <q-banner v-if="error" rounded class="bg-negative text-white q-mb-md">{{
        error
      }}</q-banner>
      <div v-else-if="!path" class="challenge-path__wait">
        <q-spinner size="2.5em" />
      </div>

      <template v-if="path">
        <header class="challenge-path__head">
          <div class="challenge-path__head-row">
            <h1 class="challenge-path__title">{{
              $t('challenge.path.title')
            }}</h1>
            <button
              type="button"
              class="challenge-path__help"
              :aria-label="$t('challenge.path.legend.title')"
              data-testid="challenge-path-legend"
              @click="legendOpen = true"
              >?</button
            >
          </div>
          <span class="challenge-path__lead">{{
            $t('challenge.path.lead')
          }}</span>
          <span
            class="challenge-path__totals"
            data-testid="challenge-path-totals"
            >{{ $t('challenge.path.totals', totals) }}</span
          >
          <span class="challenge-path__bar" aria-hidden="true"
            ><span :style="{ width: `${barWidth}%` }"
          /></span>
        </header>

        <div class="challenge-path__layout">
          <div class="challenge-path__main">
            <EndgamePathCard
              v-for="chapter in path.chapters"
              :key="chapter.key"
              :capacity="CHALLENGES"
              :chapter="chapter"
              :chosen-key="shown?.key ?? null"
              :next-key="next?.key ?? null"
              @select="select"
            />
            <div
              v-if="!hasOpeningChapter(path)"
              class="challenge-path__card cm-card"
              data-testid="challenge-path-no-opening"
            >
              <p class="challenge-path__rules">{{
                $t('challenge.path.noOpening')
              }}</p>
              <router-link to="/repertoire" class="challenge-path__link">{{
                $t('challenge.path.noOpeningLink')
              }}</router-link>
            </div>
          </div>

          <aside
            class="challenge-path__side"
            :style="{ '--side-top': `${headerHeight + 16}px` }"
          >
            <EndgameStepDetail
              v-if="wide && shown && shownChapter"
              :capacity="CHALLENGES"
              :step="shown"
              :chapter="shownChapter"
              :busy="busy"
              :error="actionError"
              @start="start"
            />

            <div
              class="challenge-path__card cm-card"
              data-testid="mind-path-badges"
            >
              <template v-if="path.badges.length">
                <span class="challenge-path__label">{{
                  $t('mindMapPath.page.badges')
                }}</span>
                <div class="challenge-path__badges">
                  <span
                    v-for="b in path.badges"
                    :key="b.badge"
                    class="challenge-path__badge"
                    ><q-icon name="military_tech" />{{
                      badgeName(b.badge)
                    }}</span
                  >
                </div>
              </template>
              <p class="challenge-path__rules">{{
                $t('challenge.path.rules')
              }}</p>
            </div>
          </aside>
        </div>
      </template>
    </div>

    <!-- A phone: the step chosen in a sheet from the bottom. -->
    <q-dialog
      :model-value="!wide && sheetOpen && !!shown"
      position="bottom"
      @hide="closeSheet"
    >
      <div v-if="shown && shownChapter" class="challenge-path__sheet">
        <EndgameStepDetail
          :capacity="CHALLENGES"
          :step="shown"
          :chapter="shownChapter"
          :busy="busy"
          :error="actionError"
          closable
          @start="start"
          @close="sheetOpen = false"
        />
      </div>
    </q-dialog>

    <q-dialog v-model="legendOpen">
      <div
        class="challenge-path__legend"
        data-testid="challenge-path-legend-dialog"
      >
        <div class="challenge-path__legend-head">
          <span class="challenge-path__legend-title">{{
            $t('challenge.path.legend.title')
          }}</span>
          <button
            type="button"
            class="challenge-path__legend-close"
            :aria-label="$t('mindMapPath.sheet.close')"
            @click="legendOpen = false"
            >✕</button
          >
        </div>
        <div
          v-for="row in LEGEND"
          :key="row.key"
          class="challenge-path__legend-row"
        >
          <span
            class="challenge-path__legend-node"
            :class="`challenge-path__legend-node--${row.key}`"
            >{{ row.glyph }}</span
          >
          <span class="challenge-path__legend-text">
            <span class="challenge-path__legend-name">{{
              $t(`challenge.path.legend.${row.key}.title`)
            }}</span>
            <span class="challenge-path__legend-desc">{{
              $t(`challenge.path.legend.${row.key}.text`)
            }}</span>
          </span>
        </div>
      </div>
    </q-dialog>

    <TreasureChest
      v-if="chests.length"
      :key="chests[0].id"
      :reward="chestReward(chests[0])"
      :note="chestNote(CHALLENGES, chests[0])"
      :opener="mindMapApi.openChest"
      :results-label="$t('mindMapPath.victory.continue')"
      @results="nextChest"
      @close="nextChest"
      @shop="chests = []"
    />
  </q-page>
</template>

<script setup>
/**
 * The défis (docs/DEFIS.md), Lizy's path `challenges` on the mind map's engine, drawn as the
 * endgames' (its cards and its step's detail): nothing ever locked. Its head (the steps mastered,
 * the bosses beaten), one card per chapter (its five tiers, then its boss): mates, endgames,
 * tactics, then one per repertoire of the player that has an opening; without any, a word on how
 * to get one. The step to play next wears a star and is shown in full until another one is
 * chosen: in the column beside the path on a wide screen, in a sheet from the bottom on a phone.
 * Chests not opened yet open on arrival.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import EndgamePathCard from '@/components/endgame/path/EndgamePathCard.vue'
import EndgameStepDetail from '@/components/endgame/path/EndgameStepDetail.vue'
import TreasureChest from '@/components/shop/TreasureChest.vue'
import { mindMapApi } from '@/services/api'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import { CHALLENGES, hasOpeningChapter } from '@/utils/challengePath'
import { chapterOf, nextStep, pathTotals } from '@/utils/endgamePath'
import {
  badgeName,
  chestNote,
  chestReward,
  findStep,
  refusalText,
  startsOnSecondClick
} from '@/utils/mindMapPath'
import { t } from '@/i18n'

definePage({
  meta: {
    auth: 'required',
    // Open to a guest (docs/GUEST.md).
    guestOpen: true,
    header: {
      title: 'challenge.path.title',
      subtitle: 'mindMapPath.page.kicker'
    }
  }
})

/** The legend's rows: a step's states, then a boss. */
const LEGEND = [
  { key: 'new', glyph: '▶' },
  { key: 'started', glyph: '1' },
  { key: 'known', glyph: '4' },
  { key: 'mastered', glyph: '✓' },
  { key: 'boss', glyph: '♛' }
]

const router = useRouter()
const $q = useQuasar()
const training = useTrainingStore()
/** A wide screen: the step's detail in the column (else in a sheet). */
const wide = computed(() => $q.screen.gt.sm)

/** @type {import('vue').Ref<import('@/utils/mindMapPath').MindPath|null>} */
const path = ref(null)
const error = ref('')
/** @type {import('vue').Ref<import('@/utils/mindMapPath').PathChest[]>} */
const chests = ref([])
/** @type {import('vue').Ref<string|null>} the step the player chose, null: the one to play next */
const chosenKey = ref(null)
const sheetOpen = ref(false)
const legendOpen = ref(false)
const busy = ref(false)
const actionError = ref('')

const next = computed(() => (path.value ? nextStep(path.value) : null))
/** The step shown in full: the one chosen, else the one to play next. */
const shown = computed(() => {
  if (!path.value) return null
  return (
    (chosenKey.value ? findStep(path.value, chosenKey.value) : null) ??
    next.value
  )
})
const shownChapter = computed(() =>
  path.value && shown.value ? chapterOf(path.value, shown.value.key) : null
)
const totals = computed(() =>
  path.value
    ? pathTotals(path.value)
    : { mastered: 0, lessons: 0, beaten: 0, bosses: 0 }
)
/** Steps mastered and bosses beaten, together. */
const barWidth = computed(() => {
  const { mastered, lessons, beaten, bosses } = totals.value
  const all = lessons + bosses
  return all ? Math.round((100 * (mastered + beaten)) / all) : 0
})

async function load() {
  error.value = ''
  try {
    path.value = await mindMapApi.path(CHALLENGES)
    chests.value = path.value?.unopenedChests ?? []
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: t('mindMapPath.page.loadError') })
  }
}

/** The app header's height: the detail column sticks under it. */
const headerHeight = ref(0)
/** @type {ResizeObserver|null} */
let headerObserver = null

onMounted(() => {
  load()
  const header = /** @type {HTMLElement|null} */ (
    document.querySelector('.app-header')
  )
  if (!header) return
  headerHeight.value = header.offsetHeight
  if (typeof ResizeObserver === 'undefined') return
  headerObserver = new ResizeObserver(() => {
    headerHeight.value = header.offsetHeight
  })
  headerObserver.observe(header)
})

onBeforeUnmount(() => headerObserver?.disconnect())

/** @param {import('@/utils/mindMapPath').PathStep} step */
function select(step) {
  // Clicked again while shown beside the path: played, as « Commencer » (a phone's sheet hides
  // the path, so a tap there only opens it again).
  if (
    wide.value &&
    chosenKey.value === step.key &&
    !busy.value &&
    startsOnSecondClick(step)
  ) {
    start(step)
    return
  }
  if (step.key !== shown.value?.key) actionError.value = ''
  chosenKey.value = step.key
  sheetOpen.value = true
}

function closeSheet() {
  sheetOpen.value = false
  actionError.value = ''
}

/** @param {import('@/utils/mindMapPath').PathStep} step */
async function start(step) {
  busy.value = true
  actionError.value = ''
  try {
    const { run } = await mindMapApi.start(CHALLENGES, step.key)
    training.current = run
    await router.push({
      path: `/training/${run.id}`,
      query: {
        mindMap: '1',
        ...(step.character ? { boss: step.character } : {})
      }
    })
  } catch (e) {
    actionError.value = refusalText(e) || apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

function nextChest() {
  chests.value = chests.value.slice(1)
}
</script>

<style scoped lang="scss">
.challenge-path {
  max-width: 1320px;
  margin: 0 auto;
}

.challenge-path__wait {
  display: grid;
  place-items: center;
  padding: 48px;
}

.challenge-path__head {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.challenge-path__head-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.challenge-path__title {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 30px;
  line-height: 1.1;
}

.challenge-path__help {
  flex: none;
  width: 44px;
  height: 44px;
  border: none;
  border-radius: 14px;
  background: var(--cm-subtle);
  color: inherit;
  font-size: 17px;
  font-weight: 800;
  cursor: pointer;

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.challenge-path__lead {
  font-size: 14.5px;
  line-height: 1.45;
  color: var(--cm-muted);
}

.challenge-path__totals {
  font-size: 14px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.challenge-path__bar {
  display: flex;
  height: 6px;
  max-width: 420px;
  border-radius: 3px;
  background: var(--cm-line);
  overflow: hidden;

  > span {
    border-radius: 3px;
    background: var(--cm-lime);
  }
}

// Phone: the path, then the badges (a step opens in a sheet). Wide screen: the path | the step's
// detail and the badges, sticky under the header.
.challenge-path__layout {
  margin-top: 20px;
  display: grid;
  gap: 24px;
  grid-template-areas: 'main' 'side';

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: minmax(0, 1fr) 380px;
    grid-template-areas: 'main side';
    align-items: start;
  }
}

.challenge-path__main {
  grid-area: main;
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

.challenge-path__side {
  grid-area: side;
  display: flex;
  flex-direction: column;
  gap: 16px;

  @media (min-width: $breakpoint-md-min) {
    position: sticky;
    top: var(--side-top, 16px);
    max-height: calc(100vh - var(--side-top, 16px) - 16px);
    overflow-y: auto;
  }
}

.challenge-path__sheet {
  width: min(520px, 100vw);
  max-height: 86vh;
  overflow-y: auto;

  :deep(.endgame-detail) {
    border-radius: 28px 28px 0 0;
  }
}

.challenge-path__card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 18px 20px;
  border-radius: 20px;
}

.challenge-path__label {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.challenge-path__rules {
  margin: 0;
  font-size: 13.5px;
  line-height: 1.45;
  color: var(--cm-muted);
}

.challenge-path__link {
  align-self: flex-start;
  color: var(--cm-brand-deep);
  font-size: 14px;
  font-weight: 800;
}

.challenge-path__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.challenge-path__badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--cm-brand-soft);
  color: var(--cm-brand-deep);
  font-size: 13px;
  font-weight: 800;
}

.challenge-path__legend {
  display: flex;
  flex-direction: column;
  gap: 14px;
  width: min(440px, calc(100vw - 32px));
  padding: 20px 22px 22px;
  border-radius: 24px;
  background: var(--cm-surface);
}

.challenge-path__legend-head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.challenge-path__legend-title {
  flex: 1;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 21px;
}

.challenge-path__legend-close {
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 12px;
  background: var(--cm-subtle);
  color: inherit;
  font-size: 15px;
  font-weight: 800;
  cursor: pointer;
}

.challenge-path__legend-row {
  display: flex;
  align-items: center;
  gap: 14px;
}

.challenge-path__legend-node {
  position: relative;
  flex: none;
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 50%;
  font-weight: 800;
  font-size: 14px;
  color: var(--cm-lime-ink);
  isolation: isolate;

  &--new {
    box-shadow: inset 0 0 0 2.5px var(--cm-lime-ink);
  }

  &--started,
  &--known {
    background: conic-gradient(var(--cm-lime) var(--p), var(--cm-line) 0);

    // The ring's number above its inner disc.
    &::before {
      content: '';
      position: absolute;
      inset: 4px;
      z-index: -1;
      border-radius: 50%;
      background: var(--cm-surface);
    }
  }

  &--started {
    --p: 10%;
  }

  &--known {
    --p: 40%;
  }

  &--mastered {
    background: var(--cm-lime);
    color: #1b1530;
  }

  &--boss {
    border-radius: 12px;
    background: radial-gradient(
      circle at 50% 30%,
      var(--cm-lime-soft),
      var(--cm-inset) 75%
    );
    box-shadow: inset 0 0 0 2px var(--cm-lime);
    font-size: 20px;
  }
}

.challenge-path__legend-text {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.challenge-path__legend-name {
  font-size: 14.5px;
  font-weight: 700;
}

.challenge-path__legend-desc {
  font-size: 13px;
  color: var(--cm-muted);
}
</style>
