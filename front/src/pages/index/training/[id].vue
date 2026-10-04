<template>
  <q-page padding>
    <div
      v-if="runner.phase.value === 'ended' && runner.run.value"
      class="training-page"
    >
      <RunRecap :run="runner.run.value">
        <template #actions>
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

      <template #info>
        <div class="text-caption text-grey"
          >Un seul essai par puzzle : en cas d’erreur, la solution
          s’affiche.</div
        >
      </template>

      <template #result>
        <div v-if="!runner.result.value" class="row items-center q-gutter-sm">
          <q-spinner size="1.5em" />
          <span>Enregistrement…</span>
        </div>
        <div
          v-else
          class="text-h6"
          :class="
            runner.result.value.success ? 'text-positive' : 'text-negative'
          "
          >{{ runner.result.value.success ? 'Réussi !' : 'Échoué' }}
          <span
            v-if="runner.result.value.data?.ratingDelta != null"
            data-testid="run-rating-delta"
            >({{
              formatRatingDelta(runner.result.value.data.ratingDelta)
            }})</span
          ></div
        >
        <q-btn
          color="primary"
          no-caps
          icon="skip_next"
          label="Suivant"
          :disable="!runner.result.value"
          data-testid="run-next"
          @click="next"
        />
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
 * (docs/REPERTOIRE.md § 15) or free study (a timer).
 */
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useQuasar } from 'quasar'
import PuzzlePlayer from '@/components/puzzle/PuzzlePlayer.vue'
import FreeRunPanel from '@/components/training/FreeRunPanel.vue'
import RepertoireDrillPlayer from '@/components/repertoire/RepertoireDrillPlayer.vue'
import RepertoireRunUnits from '@/components/repertoire/RepertoireRunUnits.vue'
import RunHeader from '@/components/training/RunHeader.vue'
import RunRecap from '@/components/training/RunRecap.vue'
import { useSessionStep } from '@/composables/session/useSessionStep'
import { useRunAlerts } from '@/composables/training/useRunAlerts'
import { useTimeboxedRun } from '@/composables/training/useTimeboxedRun'
import { sessionApi } from '@/services/api'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import { formatRatingDelta } from '@/utils/format'
import { stepModule } from '@/utils/session/steps'
import { backLabel, subjectPath } from '@/utils/training'

definePage({ meta: { auth: 'required' } })

/** After a solved puzzle, the next one comes by itself (speed matters); after a miss, the solution first. */
const AUTO_NEXT_MS = 500

/** Item types played on the puzzle board. */
const PUZZLE_ITEMS = ['woodpecker_puzzle', 'puzzle']

/** What "Terminer" leaves behind, by module. */
const STOP_MESSAGES = {
  repertoire:
    'L’unité en cours ne sera pas comptée, sauf si vous y avez déjà fait une erreur.',
  puzzles:
    'Le puzzle en cours ne sera pas compté : il vous attendra au prochain puzzle.',
  free: 'Le temps passé jusqu’ici sera compté.'
}
const route = useRoute()
const $q = useQuasar()
const store = useTrainingStore()
const runner = useTimeboxedRun()
useRunAlerts(runner)
const loading = ref(false)
const error = ref('')

/** A new object for each item: the player starts a new game. */
const puzzle = computed(() =>
  PUZZLE_ITEMS.includes(runner.item.value?.type ?? '')
    ? runner.item.value?.data.puzzle
    : null
)

const isRepertoire = computed(() => runner.run.value?.module === 'repertoire')

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

/** The session's next module to play, if it goes on. */
const nextStep = computed(() => {
  const s = session.value
  if (!s || s.status !== 'active') return null
  const step = s.steps[s.currentIndex]
  return step && step.status === 'pending' ? step : null
})

// The run is over: nothing in progress any more for the rest of the app; a session step offers
// the next module (the session follows its runs on the server).
watch(
  () => runner.phase.value,
  phase => {
    if (phase !== 'ended') return
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
