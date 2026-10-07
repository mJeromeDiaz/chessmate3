<template>
  <q-dialog
    :model-value="modelValue"
    :position="wide ? 'standard' : 'bottom'"
    :maximized="!wide"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div
      class="run-end"
      :class="{ 'run-end--wide': wide }"
      data-testid="run-end"
    >
      <div v-show="wide || !current" class="run-end__main">
        <section
          class="run-end__hero"
          :style="{ background: prof.bg, color: prof.ink }"
        >
          <div class="run-end__hero-shade" :style="{ background: prof.deep }" />
          <div class="run-end__kicker">{{ hero.kicker }}</div>
          <h2 class="run-end__title" data-testid="run-end-title">{{
            hero.title
          }}</h2>
          <div class="run-end__subtitle">{{ hero.subtitle }}</div>
          <button
            v-if="!failed"
            type="button"
            class="run-end__celebrate"
            data-testid="run-end-confetti"
            @click="burst++"
          >
            ✦ Encore des confettis
          </button>
          <ProfAvatar
            class="run-end__prof"
            :image="prof.image"
            bg="transparent"
            :deep="prof.deep"
            :ink="prof.ink"
            :glyph="prof.glyph"
          />
        </section>

        <section
          class="run-end__bubble"
          :style="{ borderColor: prof.bg }"
          data-testid="run-end-message"
        >
          <div class="run-end__bubble-kicker" :style="{ color: prof.accentInk }"
            >{{ prof.name.toUpperCase() }} · TON PROF
            {{ moduleTitle.toUpperCase() }}</div
          >
          <div>{{ message }}</div>
        </section>

        <section class="run-end__stats">
          <div
            v-for="stat in stats"
            :key="stat.label"
            class="run-end__stat"
            data-testid="run-end-stat"
          >
            <div class="run-end__stat-value">{{ stat.value }}</div>
            <div class="run-end__stat-label">
              {{ stat.label }}
            </div>
            <div
              class="run-end__stat-sub"
              :class="`run-end__stat-sub--${stat.tone}`"
              >{{ stat.sub }}</div
            >
          </div>
        </section>

        <section v-if="run.module !== 'free'" class="run-end__card">
          <div class="run-end__card-head">
            <h3 class="run-end__h3">{{ gridTitle(run) }}</h3>
            <span class="run-end__muted">{{ doneLabel }}</span>
          </div>
          <div v-if="loading" class="run-end__muted">Chargement…</div>
          <div v-else-if="error" class="run-end__error">{{ error }}</div>
          <CoordinateRibbon
            v-else-if="run.module === 'coordinates'"
            :items="items"
          />
          <template v-else>
            <div class="run-end__grid" data-testid="run-end-grid">
              <div
                v-for="item in items"
                :key="item.index"
                class="run-end__cell"
                :style="{ background: STATUS[item.status].color }"
                :title="`#${itemNumber(item)} · ${STATUS[item.status].label}`"
                :data-status="item.status"
              />
            </div>
            <div class="run-end__legend">
              <span v-for="(s, key) in STATUS" :key="key"
                ><i :style="{ background: s.color }" />{{ s.label }}</span
              >
            </div>
          </template>
        </section>

        <section
          v-if="run.module === 'free' && run.summary?.metrics?.notes"
          class="run-end__card"
        >
          <h3 class="run-end__h3">Tes notes</h3>
          <p class="run-end__notes">{{ run.summary.metrics.notes }}</p>
        </section>
      </div>

      <div class="run-end__side">
        <div class="run-end__side-scroll">
          <RunEndReplay
            v-if="current"
            :key="current.item.index"
            :missed="current"
            :has-next="nextMissed !== null"
            :paused="!!run.parentId"
            @back="current = null"
            @next="current = nextMissed"
            @reviewed="reviewed.add(current.item.index)"
          />
          <section
            v-else-if="run.module === 'coordinates'"
            class="run-end__card"
            data-testid="run-end-squares"
          >
            <h3 class="run-end__h3">Cases à retravailler</h3>
            <div class="run-end__muted">{{
              weakSquares.length
                ? 'Les cases que tu as le plus ratées dans cette série.'
                : 'Aucune erreur : toutes les cases sont justes.'
            }}</div>
            <div
              v-for="w in weakSquares"
              :key="w.square"
              class="run-end__missed"
              data-testid="run-end-square"
            >
              <div
                class="run-end__missed-n"
                :style="{ background: STATUS.fail.color }"
                >{{ w.square }}</div
              >
              <div class="run-end__missed-text">
                <div class="run-end__missed-title"
                  >{{ w.count }} erreur{{ w.count > 1 ? 's' : '' }}</div
                >
              </div>
            </div>
          </section>
          <section
            v-else-if="run.module !== 'free'"
            class="run-end__card"
            data-testid="run-end-missed"
          >
            <h3 class="run-end__h3">À revoir · {{ missed.length }}</h3>
            <div class="run-end__muted">{{
              missed.length
                ? 'Rejoue-les maintenant, tant que c’est frais.'
                : 'Rien à revoir : tout est réussi.'
            }}</div>
            <component
              :is="m.replayable ? 'button' : 'div'"
              v-for="m in missed"
              :key="m.item.index"
              :type="m.replayable ? 'button' : undefined"
              class="run-end__missed"
              :class="{ 'run-end__missed--replayable': m.replayable }"
              :data-reviewed="reviewed.has(m.item.index) || undefined"
              data-testid="run-end-missed-item"
              @click="m.replayable && (current = m)"
            >
              <div class="run-end__missed-n" :style="{ background: m.color }"
                >#{{ m.number }}</div
              >
              <div class="run-end__missed-text">
                <div class="run-end__missed-title">{{ m.title }}</div>
                <div class="run-end__muted">{{
                  m.replayable ? m.meta : `${m.meta} · non rejouable`
                }}</div>
              </div>
              <q-icon
                v-if="reviewed.has(m.item.index)"
                name="check_circle"
                size="20px"
                class="run-end__missed-done"
              />
              <span v-else-if="m.replayable" class="run-end__missed-play"
                >Rejouer ›</span
              >
            </component>
          </section>
        </div>

        <footer v-if="!current" class="run-end__footer">
          <button
            type="button"
            class="run-end__btn run-end__btn--soft"
            data-testid="run-end-close"
            @click="emit('update:modelValue', false)"
          >
            Fermer
          </button>
          <button
            v-if="run.parentId && nextStep"
            type="button"
            class="run-end__btn run-end__btn--main"
            :disabled="starting"
            data-testid="run-end-next"
            @click="emit('next')"
          >
            {{ starting ? 'Lancement…' : 'Module suivant →' }}
          </button>
          <router-link
            v-else-if="run.parentId"
            :to="`/session/${run.parentId}`"
            class="run-end__btn run-end__btn--main"
            data-testid="run-end-session"
            >Bilan de la session →</router-link
          >
          <router-link
            v-else
            :to="subjectPath(run)"
            class="run-end__btn run-end__btn--main"
            data-testid="run-end-back"
            >{{ backLabel(run) }}</router-link
          >
        </footer>
        <div v-if="nextError" class="run-end__error run-end__footer-error">{{
          nextError
        }}</div>
      </div>

      <ConfettiBurst v-if="burst > 0" :key="burst" />
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * The end-of-run review (design "Fin de séance", docs/TRAINING.md § 5 quater): the professor's
 * congratulations and message, four figures, the grid of items and those to review again. Adapted
 * to the module (puzzles, Woodpecker, repertoire units, free study, coordinates: a ribbon of the
 * answers and the squares most missed instead of the grid and the replay). Confetti only for a
 * run whose end was seen on the page and not failed. An item to review is played again in the side column
 * (`RunEndReplay`, the whole sheet on a phone), client side only.
 */
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import CoordinateRibbon from '@/components/coordinates/CoordinateRibbon.vue'
import ProfAvatar from '@/components/session/ProfAvatar.vue'
import ConfettiBurst from '@/components/training/ConfettiBurst.vue'
import RunEndReplay from '@/components/training/RunEndReplay.vue'
import { trainingApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { usePuzzleStore } from '@/stores/puzzle'
import { useGamificationStore } from '@/stores/gamification'
import { apiErrorMessage } from '@/utils/apiError'
import { missedSquares } from '@/utils/coordinates'
import { profileName } from '@/utils/profile'
import {
  STATUS,
  catalogModule,
  endHero,
  endMessage,
  endProf,
  endStats,
  gridTitle,
  isFailedRun,
  itemNumber,
  missedItems
} from '@/utils/runEnd'
import { backLabel, subjectPath } from '@/utils/training'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').TrainingRun>} */
  run: { type: Object, required: true },
  /** The run's end was seen on this page: celebrate it. */
  live: { type: Boolean, default: false },
  /** The session's next step, when the run is a step of a session that goes on. */
  nextStep: { type: Object, default: null },
  starting: { type: Boolean, default: false },
  nextError: { type: String, default: '' }
})

const emit = defineEmits({
  'update:modelValue': value => typeof value === 'boolean',
  /** Start the session's next module. */
  next: null
})

const $q = useQuasar()
const auth = useAuthStore()
const puzzles = usePuzzleStore()
const gamification = useGamificationStore()

const wide = computed(() => $q.screen.gt.sm)

/** @type {import('vue').Ref<import('@/utils/runEnd').ReviewItem[]>} */
const items = ref([])
const loading = ref(false)
const error = ref('')
const burst = ref(0)
/** Waiting for the worker before asking the run's XP again. */
const XP_RETRY_MS = 3000
/** @type {import('vue').Ref<number|null>} XP gained in the run, null while not counted */
const xp = ref(null)
/** @type {ReturnType<typeof setTimeout>|undefined} */
let xpRetry
/** Runs already celebrated: reopening the dialog does not throw confetti again. */
const celebrated = new Set()

const failed = computed(() => isFailedRun(props.run))
const name = computed(() =>
  auth.profile ? profileName(auth.profile) : 'champion'
)
const prof = computed(() => endProf(props.run.module, failed.value))
const moduleTitle = computed(() => catalogModule(props.run.module)?.title ?? '')
const hero = computed(() => endHero(props.run, name.value, failed.value))
const themeLabel = (/** @type {string} */ key) => puzzles.themeLabel(key)
const message = computed(() =>
  endMessage(props.run, items.value, name.value, themeLabel)
)
const stats = computed(() =>
  endStats(props.run, items.value, {
    xp: xp.value,
    summary: gamification.summary
  })
)
const missed = computed(() => missedItems(items.value, themeLabel))
/** Coordinates: the squares missed most often. */
const weakSquares = computed(() =>
  props.run.module === 'coordinates' ? missedSquares(items.value) : []
)
/**
 * The missed item being played again (client side only), null on the review.
 *
 * @type {import('vue').Ref<ReturnType<typeof missedItems>[number]|null>}
 */
const current = ref(null)
/** Items played again to their end, by index (in memory only). */
const reviewed = reactive(new Set())
/** The next missed item that can be played again, after the current one. */
const nextMissed = computed(() => {
  if (!current.value) return null
  const at = missed.value.findIndex(
    m => m.item.index === current.value?.item.index
  )
  return missed.value.slice(at + 1).find(m => m.replayable) ?? null
})
const doneLabel = computed(() => {
  const s = props.run.summary
  if (!s || !s.itemCount) return '0 fait'
  return `${s.itemCount} faits · ${Math.round((s.successCount / s.itemCount) * 100)} % réussis`
})

async function load() {
  items.value = []
  current.value = null
  reviewed.clear()
  xp.value = null
  clearTimeout(xpRetry)
  loading.value = true
  error.value = ''
  try {
    const [review] = await Promise.all([
      trainingApi.review(props.run.id),
      ['repertoire', 'free', 'coordinates'].includes(props.run.module)
        ? null
        : puzzles.fetchThemes().catch(() => null)
    ])
    items.value = review.items
    settleXp(review.xp)
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

/**
 * The XP of the run is written by the worker, a moment after the run: when the review comes too
 * early (nothing counted yet for a run that played something), it is asked once more a little
 * later. The level follows.
 *
 * @param {number} gained
 */
function settleXp(gained) {
  const played =
    (props.run.summary?.itemCount ?? 0) > 0 ||
    (props.run.module === 'free' &&
      (props.run.summary?.durationMs ?? 0) >= 60_000)
  if (gained > 0 || !played) {
    xp.value = gained
    gamification.load(['summary'])
    return
  }
  const runId = props.run.id
  xpRetry = setTimeout(async () => {
    try {
      const review = await trainingApi.review(runId)
      if (props.run.id === runId) xp.value = review.xp
    } catch {
      if (props.run.id === runId) xp.value = 0
    }
    gamification.load(['summary'])
  }, XP_RETRY_MS)
}

watch(
  () => [props.modelValue, props.run.id],
  ([open], previous) => {
    if (!open) {
      current.value = null
      return
    }
    if (!previous || previous[1] !== props.run.id || !items.value.length) load()
    if (props.live && !failed.value && !celebrated.has(props.run.id)) {
      celebrated.add(props.run.id)
      burst.value++
    }
  },
  { immediate: true }
)

onBeforeUnmount(() => clearTimeout(xpRetry))
</script>

<style scoped lang="scss">
.run-end {
  position: relative;
  display: flex;
  flex-direction: column;
  width: 100%;
  height: 100%;
  overflow: hidden;
  background: var(--cm-page);
  color: var(--cm-ink);
  border-radius: 30px 30px 0 0;

  &--wide {
    display: grid;
    grid-template-columns: minmax(0, 1.1fr) minmax(0, 1fr);
    width: 960px;
    max-width: 94vw;
    height: 730px;
    max-height: 92vh;
    border-radius: 30px;
  }
}

.run-end__main {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  overflow-y: auto;
  flex: none;

  .run-end--wide & {
    padding: 20px;
  }
}

.run-end__side {
  display: flex;
  flex-direction: column;
  min-height: 0;
  flex: 1;

  .run-end--wide & {
    border-left: 1px solid var(--cm-line);
  }
}

// On a phone, the whole sheet scrolls and the footer stays at the bottom.
.run-end:not(.run-end--wide) {
  overflow-y: auto;

  .run-end__main {
    overflow: visible;
  }
}

.run-end__side-scroll {
  flex: 1;
  overflow-y: auto;
  padding: 0 16px 12px;

  .run-end--wide & {
    padding: 20px 20px 12px;
  }
}

.run-end__hero {
  position: relative;
  overflow: hidden;
  flex: none;
  min-height: 130px;
  padding: 18px 124px 18px 18px;
  border-radius: 24px;

  .run-end--wide & {
    min-height: 150px;
    padding-right: 144px;
  }
}

.run-end__hero-shade {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  width: 55%;
  opacity: 0.55;
  mask-image: linear-gradient(to right, transparent, #000);
}

.run-end__kicker,
.run-end__title,
.run-end__subtitle,
.run-end__celebrate {
  position: relative;
}

.run-end__kicker {
  font-size: 11px;
  font-weight: 700;
}

.run-end__title {
  margin: 4px 0 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 36px;
  line-height: 1.05;

  .run-end--wide & {
    font-size: 44px;
  }
}

.run-end__subtitle {
  margin-top: 4px;
  font-size: 14px;
}

.run-end__celebrate {
  margin-top: 12px;
  height: 32px;
  padding: 0 12px;
  border: none;
  border-radius: 999px;
  background: #1b1530;
  color: #c6f432;
  font-weight: 700;
  font-size: 12.5px;
  cursor: pointer;
}

.run-end__prof {
  position: absolute;
  right: 4px;
  bottom: -8px;
  width: 120px;
  height: 120px;

  .run-end--wide & {
    width: 140px;
    height: 140px;
  }
}

.run-end__bubble {
  flex: none;
  padding: 12px 14px;
  background: var(--cm-surface);
  border: 2px solid;
  border-radius: 20px 4px 20px 20px;
  font-size: 14.5px;
  line-height: 1.45;
  text-wrap: pretty;
}

.run-end__bubble-kicker {
  font-size: 11px;
  font-weight: 700;
}

.run-end__stats {
  flex: none;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;

  .run-end--wide & {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

.run-end__stat {
  padding: 12px 14px;
  background: var(--cm-surface);
  border-radius: 16px;
}

.run-end__stat-value {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
  font-variant-numeric: tabular-nums;
}

.run-end__stat-label {
  font-size: 12px;
  color: var(--cm-muted);
}

.run-end__stat-sub {
  margin-top: 2px;
  font-size: 11.5px;
  font-weight: 700;

  &--good {
    color: var(--cm-lime-ink);
  }

  &--bad {
    color: var(--cm-danger);
  }

  &--muted {
    color: var(--cm-muted);
  }

  &--brand {
    color: var(--cm-brand);
  }
}

.run-end__card {
  flex: none;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 14px;
  background: var(--cm-surface);
  border-radius: 20px;
}

.run-end__card-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
}

.run-end__h3 {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 16px;
}

.run-end__muted {
  font-size: 12px;
  color: var(--cm-muted);
}

.run-end__error {
  font-size: 13px;
  color: var(--cm-danger);
}

.run-end__grid {
  display: grid;
  grid-template-columns: repeat(20, minmax(0, 1fr));
  gap: 3px;
}

.run-end__cell {
  aspect-ratio: 1;
  border-radius: 3px;
}

.run-end__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  font-size: 11px;
  color: var(--cm-muted);

  span {
    display: flex;
    align-items: center;
    gap: 5px;
  }

  i {
    width: 10px;
    height: 10px;
    border-radius: 3px;
  }
}

.run-end__notes {
  margin: 0;
  white-space: pre-wrap;
  font-size: 14px;
}

.run-end__missed {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 0;
  border-top: 1px solid var(--cm-subtle);
}

.run-end__missed--replayable {
  width: 100%;
  border-right: none;
  border-bottom: none;
  border-left: none;
  background: none;
  color: inherit;
  font: inherit;
  text-align: left;
  cursor: pointer;

  &:hover .run-end__missed-play {
    text-decoration: underline;
  }
}

.run-end__missed-play {
  flex: none;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--cm-brand);
}

.run-end__missed-done {
  flex: none;
  color: var(--cm-lime-ink);
}

.run-end__missed-n {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 12px;
  color: #1b1530;
  font-weight: 700;
  font-size: 12.5px;
}

.run-end__missed-text {
  flex: 1;
  min-width: 0;
}

.run-end__missed-title {
  overflow: hidden;
  font-weight: 700;
  font-size: 14px;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.run-end__footer {
  display: flex;
  gap: 8px;
  flex: none;
  padding: 12px 16px 24px;
  background: var(--cm-surface);
  border-top: 1px solid var(--cm-line);

  .run-end--wide & {
    padding: 14px 20px 20px;
    background: transparent;
    border-top: none;
  }

  .run-end:not(.run-end--wide) & {
    position: sticky;
    bottom: 0;
  }
}

.run-end__footer-error {
  padding: 0 20px 12px;
}

.run-end__btn {
  display: flex;
  align-items: center;
  justify-content: center;
  flex: 1;
  height: 52px;
  border: none;
  border-radius: 16px;
  font-weight: 700;
  font-size: 14px;
  text-decoration: none;
  cursor: pointer;

  &--soft {
    background: var(--cm-subtle);
    color: var(--cm-ink);
  }

  &--main {
    flex: 1.4;
    background: var(--cm-ink);
    color: var(--cm-page);

    &:hover {
      color: var(--cm-page);
    }
  }

  &:disabled {
    opacity: 0.6;
    cursor: default;
  }
}
</style>
