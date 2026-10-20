<template>
  <q-dialog
    :model-value="modelValue"
    maximized
    transition-show="fade"
    transition-hide="fade"
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div class="run-result" data-testid="run-end" :data-view="view">
      <RunResultScreen
        v-if="view === 'result' && kind"
        :kind="kind"
        :module="run.module"
        :score="score"
        :ring="streak ? streakRing(run) : rush ? rushRing(run) : null"
        :note="streak ? streakNote(run) : ''"
        :caption="rush ? $t('puzzleRush.result.points') : ''"
        :lines="rush ? rushLines(run, rushXp) : []"
        :badges="streak ? wonBadges(run.summary?.metrics) : []"
        :fixable="fixable.length"
        :confetti="confetti"
        :busy="starting"
        :error="nextError"
        @action="onAction"
      />
      <RunFixErrors
        v-else-if="view === 'fix'"
        :module="run.module"
        :unit="run.summary?.metrics?.unit"
        :numbers="fixable.map(m => m.number)"
        @start="startReplay"
        @later="close"
      />
      <div v-else-if="view === 'replay' && current" class="run-result__replay">
        <RunEndReplay
          :key="current.item.index"
          :missed="current"
          :has-next="nextFixable !== null"
          :paused="!!run.parentId"
          :back-label="$t('training.replay.backToResult')"
          @back="view = 'result'"
          @next="current = nextFixable"
        />
      </div>
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * The end of a run (designs "Résultat de leçon" and "Corriger ses erreurs", docs/TRAINING.md
 * § 5 quater): the result screen for its share solved (perfect, close, fail; free study: done),
 * then, for a run with mistakes that can be played again, the screen that deals them and their
 * replay one after the other, client side only (`RunEndReplay`). The next lesson is the session's
 * next module, the session's review, or the module's page. Confetti only for a perfect run whose
 * end was seen on the page, once. A puzzle streak shows its best streak and its record instead of a
 * share (every streak ends on a miss); confetti for a record. A puzzle rush is judged on its
 * accuracy (its share solved) but shows its points, with its speed, accuracy and XP together. A
 * whole parcours shows its tiers' score, without mistakes to correct.
 */
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import RunEndReplay from '@/components/training/RunEndReplay.vue'
import RunFixErrors from '@/components/training/result/RunFixErrors.vue'
import RunResultScreen from '@/components/training/result/RunResultScreen.vue'
import { trainingApi } from '@/services/api'
import { usePuzzleStore } from '@/stores/puzzle'
import { rushLines, rushRing } from '@/utils/puzzleRush'
import { wonBadges } from '@/utils/puzzleStreak'
import { missedItems } from '@/utils/runEnd'
import {
  fixableItems,
  resultKind,
  resultScore,
  streakNote,
  streakRing
} from '@/utils/runResult'
import { subjectPath } from '@/utils/training'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  /** @type {import('vue').PropType<import('@/composables/training/useTimeboxedRun').TrainingRun>} */
  run: { type: Object, required: true },
  /** The run's end was seen on this page: celebrate it. */
  live: { type: Boolean, default: false },
  /** The session's next step, when the run is a step of a session that goes on. */
  nextStep: { type: Object, default: null },
  starting: { type: Boolean, default: false },
  nextError: { type: String, default: '' },
  /**
   * Its mistakes can be read and corrected; not for a whole parcours (docs/PARCOURS.md), whose
   * score adds up its tiers' runs.
   */
  reviewable: { type: Boolean, default: true }
})

const emit = defineEmits({
  'update:modelValue': value => typeof value === 'boolean',
  /** Start the session's next module. */
  next: null
})

const router = useRouter()
const puzzles = usePuzzleStore()

/** @type {import('vue').Ref<'result'|'fix'|'replay'>} */
const view = ref('result')
/** @type {import('vue').Ref<import('@/utils/runEnd').ReviewItem[]>} */
const items = ref([])
/** @type {string|null} the run whose items are loaded */
let loadedRun = null
/** Runs already celebrated: reopening the screen throws no confetti again. */
const celebrated = new Set()
const confetti = ref(false)

const kind = computed(() => resultKind(props.run))
const score = computed(() => resultScore(props.run))
/** A puzzle streak: its record on the ring and under it. */
const streak = computed(() => props.run.module === 'puzzle_streak')
/** A puzzle rush: its points, the ring filled by its accuracy; speed, accuracy and XP under it. */
const rush = computed(() => props.run.module === 'puzzle_rush')
/** The XP the run gained (its review's), null until read. */
const xp = ref(/** @type {number|null} */ (null))
/**
 * The rush's XP: what the ledger holds once the outbox wrote it, the rule's before (the daily cap
 * may lower it, the ledger says).
 */
const rushXp = computed(() =>
  (xp.value ?? 0) > 0 ? xp.value : (props.run.summary?.metrics?.xp ?? null)
)
const themeLabel = (/** @type {string} */ key) => puzzles.themeLabel(key)
const fixable = computed(() =>
  fixableItems(missedItems(items.value, themeLabel))
)
/** @type {import('vue').Ref<ReturnType<typeof missedItems>[number]|null>} */
const current = ref(null)
const nextFixable = computed(() => {
  if (!current.value) return null
  const at = fixable.value.findIndex(
    m => m.item.index === current.value?.item.index
  )
  return fixable.value[at + 1] ?? null
})

/** The missed items, for their count and replay (no review for free study). */
async function load() {
  if (
    loadedRun === props.run.id ||
    props.run.module === 'free' ||
    !props.reviewable
  )
    return
  const runId = props.run.id
  try {
    const [review] = await Promise.all([
      trainingApi.review(runId),
      ['repertoire', 'coordinates'].includes(props.run.module)
        ? null
        : puzzles.fetchThemes().catch(() => null)
    ])
    if (props.run.id !== runId) return
    items.value = review.items
    xp.value = typeof review.xp === 'number' ? review.xp : null
    loadedRun = runId
  } catch {
    // Without the review, nothing to correct: the result screen still shows.
    items.value = []
  }
}

function close() {
  emit('update:modelValue', false)
}

/** @param {import('@/utils/runResult').ResultAction} action */
function onAction(action) {
  if (action === 'close') close()
  else if (action === 'fix') view.value = 'fix'
  else if (props.run.parentId && props.nextStep) emit('next')
  else if (props.run.parentId) router.push(`/session/${props.run.parentId}`)
  else router.push(subjectPath(props.run))
}

function startReplay() {
  current.value = fixable.value[0] ?? null
  view.value = current.value ? 'replay' : 'result'
}

// Back from the mistakes, the result plays again, without its confetti.
watch(view, v => {
  if (v !== 'result') confetti.value = false
})

watch(
  () => [props.modelValue, props.run.id],
  ([open]) => {
    view.value = 'result'
    current.value = null
    if (!open) return
    if (loadedRun !== props.run.id) {
      items.value = []
      xp.value = null
    }
    load()
    confetti.value =
      props.live &&
      (kind.value === 'perfect' ||
        kind.value === 'done' ||
        kind.value === 'record') &&
      !celebrated.has(props.run.id)
    if (confetti.value) celebrated.add(props.run.id)
  },
  { immediate: true }
)
</script>

<style scoped lang="scss">
.run-result {
  position: relative;
  width: 100%;
  height: 100%;
  overflow: hidden;
  background: #1b1530;
}

.run-result__replay {
  position: absolute;
  inset: 0;
  overflow-y: auto;
  background: var(--cm-page);
  padding: max(16px, env(safe-area-inset-top)) 16px 24px;

  // As wide as a module's puzzle screen: the board as tall as the window on a computer.
  > * {
    max-width: 1320px;
    margin: 0 auto;
  }
}
</style>
