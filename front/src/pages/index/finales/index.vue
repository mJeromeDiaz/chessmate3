<template>
  <q-page padding>
    <div class="endgame-path" data-testid="endgame-path">
      <q-banner v-if="error" rounded class="bg-negative text-white q-mb-md">{{
        error
      }}</q-banner>
      <div v-else-if="!path" class="endgame-path__wait">
        <q-spinner size="2.5em" />
      </div>

      <template v-if="path">
        <header class="endgame-path__head">
          <div class="endgame-path__head-row">
            <h1 class="endgame-path__title">{{ $t('endgame.path.title') }}</h1>
            <q-btn
              flat
              no-caps
              icon="tune"
              :label="$t('endgame.path.free')"
              to="/finales/libre"
              data-testid="endgame-path-free"
            />
            <button
              type="button"
              class="endgame-path__help"
              :aria-label="$t('endgame.path.legend.title')"
              data-testid="endgame-path-legend"
              @click="legendOpen = true"
              >?</button
            >
          </div>
          <span class="endgame-path__lead">{{ $t('endgame.path.lead') }}</span>
          <span
            class="endgame-path__totals"
            data-testid="endgame-path-totals"
            >{{ $t('endgame.path.totals', totals) }}</span
          >
          <span class="endgame-path__bar" aria-hidden="true"
            ><span :style="{ width: `${barWidth}%` }"
          /></span>
        </header>

        <div class="endgame-path__layout">
          <div class="endgame-path__main">
            <EndgamePathCard
              v-for="chapter in chapters"
              :key="chapter.key"
              :chapter="chapter"
              :chosen-key="shown?.key ?? null"
              :next-key="next?.key ?? null"
              @select="select"
            />
            <EndgameAshesCard
              v-if="ashes"
              :step="ashes.boss"
              :chosen="shown?.key === ashes.boss.key"
              :next="next?.key === ashes.boss.key"
              @select="select"
            />
          </div>

          <aside
            class="endgame-path__side"
            :style="{ '--side-top': `${headerHeight + 16}px` }"
          >
            <EndgameStepDetail
              v-if="wide && shown && shownChapter"
              :step="shown"
              :chapter="shownChapter"
              :busy="busy"
              :error="actionError"
              :demoable="!!demoLessons[shown.key]"
              @start="start"
              @demo="openDemo"
            />

            <div
              class="endgame-path__card cm-card"
              data-testid="mind-path-badges"
            >
              <template v-if="path.badges.length">
                <span class="endgame-path__label">{{
                  $t('mindMapPath.page.badges')
                }}</span>
                <div class="endgame-path__badges">
                  <span
                    v-for="b in path.badges"
                    :key="b.badge"
                    class="endgame-path__badge"
                    ><q-icon name="military_tech" />{{
                      badgeName(b.badge)
                    }}</span
                  >
                </div>
              </template>
              <p class="endgame-path__rules">{{ $t('endgame.path.rules') }}</p>
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
      <div v-if="shown && shownChapter" class="endgame-path__sheet">
        <EndgameStepDetail
          :step="shown"
          :chapter="shownChapter"
          :busy="busy"
          :error="actionError"
          :demoable="!!demoLessons[shown.key]"
          closable
          @start="start"
          @demo="openDemo"
          @close="sheetOpen = false"
        />
      </div>
    </q-dialog>

    <q-dialog v-model="legendOpen">
      <div
        class="endgame-path__legend"
        data-testid="endgame-path-legend-dialog"
      >
        <div class="endgame-path__legend-head">
          <span class="endgame-path__legend-title">{{
            $t('endgame.path.legend.title')
          }}</span>
          <button
            type="button"
            class="endgame-path__legend-close"
            :aria-label="$t('mindMapPath.sheet.close')"
            @click="legendOpen = false"
            >✕</button
          >
        </div>
        <div
          v-for="row in LEGEND"
          :key="row.key"
          class="endgame-path__legend-row"
        >
          <span
            class="endgame-path__legend-node"
            :class="`endgame-path__legend-node--${row.key}`"
            >{{ row.glyph }}</span
          >
          <span class="endgame-path__legend-text">
            <span class="endgame-path__legend-name">{{
              $t(`endgame.path.legend.${row.key}.title`)
            }}</span>
            <span class="endgame-path__legend-desc">{{
              $t(`endgame.path.legend.${row.key}.text`)
            }}</span>
          </span>
        </div>
      </div>
    </q-dialog>

    <q-dialog v-model="demoOpen" :maximized="$q.screen.lt.sm">
      <EndgameDemo
        v-if="demo"
        :lesson-key="demo.key"
        :fen="demo.textbook"
        :goal="demo.goal"
        :result="demo.result"
        :hold-moves="demo.holdMoves"
        @close="demoOpen = false"
      />
    </q-dialog>

    <TreasureChest
      v-if="chests.length"
      :key="chests[0].id"
      :reward="chestReward(chests[0])"
      :note="chestNote(ENDGAMES, chests[0])"
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
 * The endgames path (docs/ENDGAMES.md, § 10; design « Parcours echiqueens »): the path `endgames`
 * of the mind map's engine, nothing ever locked. Its head (the lessons mastered, the bosses
 * beaten), one card per mini-parcours (its lessons, then its boss), the Roi des cendres' card last.
 * The step to play next wears a star and is shown in full until another one is chosen: in the
 * column beside the path on a wide screen, in a sheet from the bottom on a phone. The module's
 * free page stays at `/finales/libre`. Maia downloads while a lesson is shown (the bosses play the
 * tablebase's perfect defence). A lesson's demonstration plays its textbook position (GET
 * /endgames gives them). Chests not opened yet open on arrival.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useQuasar } from 'quasar'
import EndgameDemo from '@/components/endgame/EndgameDemo.vue'
import EndgameAshesCard from '@/components/endgame/path/EndgameAshesCard.vue'
import EndgamePathCard from '@/components/endgame/path/EndgamePathCard.vue'
import EndgameStepDetail from '@/components/endgame/path/EndgameStepDetail.vue'
import TreasureChest from '@/components/shop/TreasureChest.vue'
import { useMaia } from '@/composables/maia/useMaia'
import { endgameApi, mindMapApi } from '@/services/api'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import {
  ashesChapter,
  chapterOf,
  miniPaths,
  nextStep,
  pathTotals
} from '@/utils/endgamePath'
import {
  badgeName,
  chestNote,
  chestReward,
  ENDGAMES,
  findStep,
  isBoss,
  refusalText
} from '@/utils/mindMapPath'
import { t } from '@/i18n'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'endgame.path.title',
      subtitle: 'mindMapPath.page.kicker'
    }
  }
})

/** The legend's rows: a lesson's states, then a boss. */
const LEGEND = [
  { key: 'new', glyph: '▶' },
  { key: 'started', glyph: '1' },
  { key: 'known', glyph: '4' },
  { key: 'mastered', glyph: '✓' },
  { key: 'boss', glyph: '♜' }
]

const router = useRouter()
const $q = useQuasar()
const maia = useMaia()
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

const chapters = computed(() => (path.value ? miniPaths(path.value) : []))
const ashes = computed(() => (path.value ? ashesChapter(path.value) : null))
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
/** Lessons mastered and bosses beaten, together. */
const barWidth = computed(() => {
  const { mastered, lessons, beaten, bosses } = totals.value
  const all = lessons + bosses
  return all ? Math.round((100 * (mastered + beaten)) / all) : 0
})

/** Maia for a lesson's run (46 MB once, cached after); the bosses do without. */
watch(shown, step => {
  if (step && !isBoss(step.kind)) maia.preload().catch(() => {})
})

/**
 * The lessons' textbook positions (GET /endgames), for their demonstrations: by key.
 *
 * @type {import('vue').Ref<Record<string, {key: string, textbook: string, goal: any, result: any, holdMoves: number|null}>>}
 */
const demoLessons = ref({})
const demoOpen = ref(false)
/** @type {import('vue').Ref<{key: string, textbook: string, goal: any, result: any, holdMoves: number|null}|null>} */
const demo = ref(null)

/** @param {import('@/utils/mindMapPath').PathStep} step */
function openDemo(step) {
  const lesson = demoLessons.value[step.key]
  if (!lesson) return
  demo.value = lesson
  demoOpen.value = true
}

async function loadDemoLessons() {
  try {
    const overview = await endgameApi.overview()
    demoLessons.value = Object.fromEntries(
      overview.lessons.map((/** @type {any} */ l) => [l.key, l])
    )
  } catch {
    // No demonstration button: the path works without it.
  }
}

async function load() {
  error.value = ''
  try {
    path.value = await mindMapApi.path(ENDGAMES)
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
  loadDemoLessons()
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
    const { run } = await mindMapApi.start(ENDGAMES, step.key)
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
.endgame-path {
  max-width: 1320px;
  margin: 0 auto;
}

.endgame-path__wait {
  display: grid;
  place-items: center;
  padding: 48px;
}

.endgame-path__head {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.endgame-path__head-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.endgame-path__title {
  flex: 1;
  min-width: 0;
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 30px;
  line-height: 1.1;
}

.endgame-path__help {
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

.endgame-path__lead {
  font-size: 14.5px;
  line-height: 1.45;
  color: var(--cm-muted);
}

.endgame-path__totals {
  font-size: 14px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.endgame-path__bar {
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
.endgame-path__layout {
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

.endgame-path__main {
  grid-area: main;
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

.endgame-path__side {
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

.endgame-path__sheet {
  width: min(520px, 100vw);
  max-height: 86vh;
  overflow-y: auto;

  :deep(.endgame-detail) {
    border-radius: 28px 28px 0 0;
  }
}

.endgame-path__card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 18px 20px;
  border-radius: 20px;
}

.endgame-path__label {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--cm-muted);
}

.endgame-path__rules {
  margin: 0;
  font-size: 13.5px;
  line-height: 1.45;
  color: var(--cm-muted);
}

.endgame-path__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.endgame-path__badge {
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

.endgame-path__legend {
  display: flex;
  flex-direction: column;
  gap: 14px;
  width: min(440px, calc(100vw - 32px));
  padding: 20px 22px 22px;
  border-radius: 24px;
  background: var(--cm-surface);
}

.endgame-path__legend-head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.endgame-path__legend-title {
  flex: 1;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 21px;
}

.endgame-path__legend-close {
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

.endgame-path__legend-row {
  display: flex;
  align-items: center;
  gap: 14px;
}

.endgame-path__legend-node {
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

  &--new {
    box-shadow: inset 0 0 0 2.5px var(--cm-lime-ink);
  }

  &--started,
  &--known {
    background: conic-gradient(var(--cm-lime) var(--p), var(--cm-line) 0);

    &::before {
      content: '';
      position: absolute;
      inset: 4px;
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

// The ring's number above its inner disc.
.endgame-path__legend-node--started,
.endgame-path__legend-node--known {
  isolation: isolate;

  &::before {
    z-index: -1;
  }
}

.endgame-path__legend-text {
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.endgame-path__legend-name {
  font-size: 14.5px;
  font-weight: 700;
}

.endgame-path__legend-desc {
  font-size: 13px;
  color: var(--cm-muted);
}
</style>
