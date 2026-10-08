<template>
  <q-page padding>
    <div
      v-if="runner.phase.value === 'ended' && runner.run.value && !holdEnd"
      class="training-page"
    >
      <RunRecap :run="runner.run.value">
        <template #actions>
          <q-btn
            v-if="hasResult"
            outline
            no-caps
            icon="emoji_events"
            label="Voir le résultat"
            data-testid="run-end-open"
            @click="endOpen = true"
          />
          <template v-if="runner.run.value.parentId">
            <q-btn
              v-if="nextStep"
              color="primary"
              no-caps
              unelevated
              icon="skip_next"
              :label="`Module suivant : ${stepModule(nextStep)?.title ?? nextStep.module}`"
              :loading="sessionStep.starting.value"
              data-testid="session-next"
              @click="sessionStep.start(runner.run.value.parentId)"
            />
            <q-btn
              :color="nextStep ? undefined : 'primary'"
              :flat="!!nextStep"
              no-caps
              :label="
                nextStep ? 'Voir la session' : 'Voir le bilan de la session'
              "
              :to="`/session/${runner.run.value.parentId}`"
              data-testid="session-open"
            />
            <div v-if="sessionStep.error.value" class="text-negative q-ml-sm">{{
              sessionStep.error.value
            }}</div>
          </template>
          <q-btn
            v-else
            color="primary"
            no-caps
            :label="backLabel(runner.run.value)"
            :to="subjectPath(runner.run.value)"
            data-testid="run-back"
          />
        </template>
      </RunRecap>
      <RepertoireRunUnits
        v-if="isRepertoire"
        :run-id="runner.run.value.id"
        class="q-mt-md"
      />
      <RunResult
        v-if="hasResult"
        v-model="endOpen"
        :run="runner.run.value"
        :live="seenRunning.has(runner.run.value.id)"
        :next-step="nextStep"
        :starting="sessionStep.starting.value"
        :next-error="sessionStep.error.value"
        @next="sessionStep.start(runner.run.value.parentId)"
      />
    </div>

    <RepertoireDrillPlayer
      v-else-if="
        runner.phase.value === 'running' && isRepertoire && runner.item.value
      "
      :runner="runner"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        />
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>
    </RepertoireDrillPlayer>

    <CoordinatesPlayer
      v-else-if="
        (runner.phase.value === 'running' || runner.phase.value === 'timeUp') &&
        runner.item.value?.type === 'coordinates_series'
      "
      :runner="runner"
      :item="runner.item.value"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        />
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>
    </CoordinatesPlayer>

    <BlindfoldPuzzlePlayer
      v-else-if="
        runner.phase.value === 'running' &&
        runner.item.value?.type === 'blindfold_puzzle'
      "
      :item="runner.item.value"
      :xp="runner.xp.value"
      :actions="blindfoldActions"
      @resolve="onBlindfoldResolve"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        >
          <div class="text-subtitle2" data-testid="run-progress">
            {{ runner.played.value }} puzzles ·
            {{ runner.solved.value }} résolus
          </div>
        </RunHeader>
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>
    </BlindfoldPuzzlePlayer>

    <EvaluationPlayer
      v-else-if="evalItem && (runner.phase.value === 'running' || holdEnd)"
      ref="evalPlayer"
      :item="evalItem"
      :result="evalResult"
      :xp="runner.xp.value"
      :offset-ms="runner.offsetMs.value"
      :actions="evaluationActions"
      @resolve="onEvaluationResolve"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        >
          <div class="text-subtitle2" data-testid="run-progress">
            Position {{ evalItem.data.index }} / {{ evalItem.data.count }} ·
            {{ runner.solved.value }} juste{{
              runner.solved.value > 1 ? 's' : ''
            }}
          </div>
        </RunHeader>
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>
    </EvaluationPlayer>

    <FreeRunPanel
      v-else-if="
        runner.phase.value === 'running' &&
        runner.item.value?.type === 'free_timer'
      "
      :item="runner.item.value"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        />
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>
    </FreeRunPanel>

    <PuzzlePlayer
      v-else-if="runner.phase.value === 'running' && puzzle"
      :puzzle="puzzle"
      :loading="loading"
      after-mistake="showSolution"
      :module="runner.run.value?.module ?? 'puzzles'"
      :xp="runner.xp.value"
      :rating-delta="runner.result.value?.data?.ratingDelta ?? null"
      :actions="missActions"
      @resolve="onResolve"
    >
      <template #header>
        <RunHeader
          :remaining-ms="runner.remainingMs.value"
          :budget-seconds="runner.run.value?.budgetSeconds ?? 1"
          @stop="confirmStop"
        >
          <div class="text-subtitle2" data-testid="run-progress">
            {{ runner.played.value }} puzzles ·
            {{ runner.solved.value }} réussis
          </div>
        </RunHeader>
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>

      <template #chips>
        <span class="play-chip"
          >Un seul essai<q-tooltip
            >En cas d’erreur, la solution s’affiche.</q-tooltip
          ></span
        >
      </template>
    </PuzzlePlayer>

    <div
      v-else
      class="column items-center q-gutter-md q-pa-lg"
      data-testid="run-waiting"
    >
      <q-spinner size="3em" />
      <div v-if="runner.phase.value === 'timeUp'" class="text-h6"
        >Temps écoulé…</div
      >
      <div v-if="error" class="text-negative">{{ error }}</div>
    </div>
  </q-page>
</template>

<script setup>
/**
 * A timed run (docs/TRAINING.md): countdown on the server's clock, items one after the other,
 * recap at the end. Reloading or coming back before the end resumes the same run and item. The
 * module decides the player: puzzles (Woodpecker, rated puzzles), the repertoire test
 * (docs/REPERTOIRE.md § 15), free study (a timer), a coordinates series, blindfold puzzles (docs/BLINDFOLD.md) or
 * positions to evaluate (docs/EVALUATION.md).
 */
import { computed, reactive, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useQuasar } from 'quasar'
import BlindfoldPuzzlePlayer from '@/components/blindfold/BlindfoldPuzzlePlayer.vue'
import CoordinatesPlayer from '@/components/coordinates/CoordinatesPlayer.vue'
import EvaluationPlayer from '@/components/evaluation/EvaluationPlayer.vue'
import PuzzlePlayer from '@/components/puzzle/PuzzlePlayer.vue'
import FreeRunPanel from '@/components/training/FreeRunPanel.vue'
import RepertoireDrillPlayer from '@/components/repertoire/RepertoireDrillPlayer.vue'
import RepertoireRunUnits from '@/components/repertoire/RepertoireRunUnits.vue'
import RunHeader from '@/components/training/RunHeader.vue'
import RunRecap from '@/components/training/RunRecap.vue'
import RunResult from '@/components/training/RunResult.vue'
import { useSessionStep } from '@/composables/session/useSessionStep'
import { useRunAlerts } from '@/composables/training/useRunAlerts'
import { useTimeboxedRun } from '@/composables/training/useTimeboxedRun'
import { sessionApi } from '@/services/api'
import { useGamificationStore } from '@/stores/gamification'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import { stepModule } from '@/utils/session/steps'
import { resultKind } from '@/utils/runResult'
import { backLabel, subjectPath } from '@/utils/training'

definePage({ meta: { auth: 'required' } })

/**
 * After a solved puzzle, the next one comes by itself once the short success animation played
 * (speed matters); after a miss, the solution and the result sheet wait for "Suivant".
 */
const AUTO_NEXT_MS = 1200

/** Item types played on the puzzle board. */
const PUZZLE_ITEMS = ['woodpecker_puzzle', 'puzzle']

/** What "Terminer" leaves behind, by module. */
const STOP_MESSAGES = {
  repertoire:
    'L’unité en cours ne sera pas comptée, sauf si vous y avez déjà fait une erreur.',
  puzzles:
    'Le puzzle en cours ne sera pas compté : il vous attendra au prochain puzzle.',
  free: 'Le temps passé jusqu’ici sera compté.',
  coordinates:
    'Tes réponses seront comptées, mais une série arrêtée avant la fin ne valide pas.',
  blindfold: 'Le puzzle en cours ne sera pas compté.',
  evaluation: 'La position à l’écran ne sera pas comptée.'
}
const route = useRoute()
const $q = useQuasar()
const store = useTrainingStore()
const gamification = useGamificationStore()
const runner = useTimeboxedRun()
const loading = ref(false)
const error = ref('')

/** A new object for each item: the player starts a new game. */
const puzzle = computed(() =>
  PUZZLE_ITEMS.includes(runner.item.value?.type ?? '')
    ? runner.item.value?.data.puzzle
    : null
)

/** The result sheet's button after a failed item or a failed request (a success moves on by itself). */
const missActions = computed(() =>
  (runner.result.value && !runner.result.value.success) || error.value
    ? [
        {
          label: 'Suivant →',
          primary: true,
          disable: loading.value,
          testid: 'run-next',
          onClick: next
        }
      ]
    : []
)

/** A blindfold puzzle never moves on by itself: the final position stays to be looked at. */
const blindfoldActions = computed(() => [
  {
    label: 'Suivant →',
    primary: true,
    disable: (!runner.result.value && !error.value) || loading.value,
    testid: 'run-next',
    onClick: next
  }
])

const isRepertoire = computed(() => runner.run.value?.module === 'repertoire')

/**
 * The position being evaluated, kept once the run closed on the last one: its correction stays on
 * screen (`holdEnd`) until "Voir le résultat".
 *
 * @type {import('vue').Ref<import('@/composables/training/useTimeboxedRun').RunItem|null>}
 */
const evalItem = ref(null)
const holdEnd = ref(false)
/** @type {import('vue').Ref<{unlock: () => void}|null>} */
const evalPlayer = ref(null)
watch(
  () => runner.item.value,
  item => {
    if (item?.type === 'evaluation_position') evalItem.value = item
    else if (item) evalItem.value = null
  }
)
/** The verdict and correction of the position on screen. */
const evalResult = computed(() =>
  evalItem.value && runner.result.value?.itemId === evalItem.value.id
    ? runner.result.value.data
    : null
)
const evaluationActions = computed(() => [
  {
    label:
      runner.phase.value === 'running'
        ? 'Position suivante →'
        : 'Voir le résultat',
    primary: true,
    disable: loading.value,
    testid: 'run-next',
    onClick: async () => {
      holdEnd.value = false
      if (runner.phase.value === 'running') await next()
    }
  }
])

/** @param {{guess: number|null, plan: string|null}} answer */
async function onEvaluationResolve(answer) {
  // Held before the answer leaves: the last one closes the run, and the player must not be
  // unmounted (its sheet lost, the review opened) before the correction is shown.
  holdEnd.value = true
  try {
    const result = await runner.submit({ evaluation: answer })
    if (result) return
    holdEnd.value = false
    evalPlayer.value?.unlock()
  } catch (e) {
    holdEnd.value = false
    error.value = apiErrorMessage(e)
    evalPlayer.value?.unlock()
  }
}

/**
 * @param {string} _outcome
 * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
 */
async function onResolve(_outcome, report) {
  try {
    const result = await runner.submit(report)
    if (result?.success) setTimeout(next, AUTO_NEXT_MS)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

/**
 * A blindfold puzzle's outcome: no automatic next puzzle, the final position stays on the board.
 *
 * @param {string} _status
 * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
 */
async function onBlindfoldResolve(_status, report) {
  try {
    await runner.submit(report)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

async function next() {
  if (runner.phase.value !== 'running' || loading.value) return
  loading.value = true
  error.value = ''
  try {
    await runner.next()
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: 'Séance introuvable.' })
  } finally {
    loading.value = false
  }
}

function confirmStop() {
  $q.dialog({
    title: 'Terminer la séance ?',
    message:
      STOP_MESSAGES[runner.run.value?.module ?? ''] ??
      'Le puzzle en cours ne sera pas compté.',
    cancel: true
  }).onOk(() => runner.stop().catch(e => (error.value = apiErrorMessage(e))))
}

/** The session this run is a step of, once the run is over. */
/** @type {import('vue').Ref<import('@/utils/session/steps').TrainingSession|null>} */
const session = ref(null)
const sessionStep = useSessionStep()
useRunAlerts(runner, { session })

/** The session's next module to play, if it goes on. */
const nextStep = computed(() => {
  const s = session.value
  if (!s || s.status !== 'active') return null
  const step = s.steps[s.currentIndex]
  return step && step.status === 'pending' ? step : null
})

/**
 * The end-of-run result (`RunResult`): opens when the run is over (also on a run reopened once
 * over), unless nothing was played.
 */
const endOpen = ref(false)
const hasResult = computed(
  () => !!runner.run.value && resultKind(runner.run.value) !== null
)
/** The review waits for the last correction to be left (`holdEnd`). */
let endWaiting = false

function openEnd() {
  if (!hasResult.value) return
  if (holdEnd.value) {
    endWaiting = true
    return
  }
  // The day's first exercise may have been in this run: its streak celebration comes first.
  gamification
    .celebrateStreak({ afterExercise: false })
    .then(() => (endOpen.value = true))
}

watch(holdEnd, held => {
  if (!held && endWaiting) {
    endWaiting = false
    openEnd()
  }
})
/** Runs seen running on this page: their end is celebrated. */
const seenRunning = reactive(new Set())

// The run is over: nothing in progress any more for the rest of the app; a session step offers
// the next module (the session follows its runs on the server).
watch(
  () => runner.phase.value,
  phase => {
    if (phase === 'running' && runner.run.value)
      seenRunning.add(runner.run.value.id)
    if (phase !== 'ended') return
    openEnd()
    if (store.current?.id === runner.run.value?.id) store.current = null
    const parentId = runner.run.value?.parentId
    if (parentId) {
      sessionApi
        .get(parentId)
        .then(s => (session.value = s))
        .catch(() => {})
    }
  },
  { immediate: true }
)

// Also when a session's next module replaces this run: the page stays, the run changes.
watch(
  () => route.params.id,
  async id => {
    if (!id) return
    loading.value = true
    error.value = ''
    session.value = null
    endOpen.value = false
    holdEnd.value = false
    evalItem.value = null
    try {
      await runner.resume(String(id))
    } catch (e) {
      error.value = apiErrorMessage(e, { 404: 'Séance introuvable.' })
    } finally {
      loading.value = false
    }
  },
  { immediate: true }
)
</script>

<style scoped>
.training-page {
  max-width: 700px;
  margin: 0 auto;
}
</style>
