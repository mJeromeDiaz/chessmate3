<template>
  <q-page padding>
    <div
      v-if="runner.phase.value === 'ended' && runner.run.value"
      class="training-page"
    >
      <RunRecap :run="runner.run.value">
        <template #actions>
          <q-btn
            color="primary"
            no-caps
            :label="isRepertoire ? 'Retour aux répertoires' : 'Retour au set'"
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
          >{{ runner.result.value.success ? 'Réussi !' : 'Échoué' }}</div
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
 * module decides the player: Woodpecker puzzles, or the repertoire test (docs/REPERTOIRE.md § 15).
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useQuasar } from 'quasar'
import PuzzlePlayer from '@/components/puzzle/PuzzlePlayer.vue'
import RepertoireDrillPlayer from '@/components/repertoire/RepertoireDrillPlayer.vue'
import RepertoireRunUnits from '@/components/repertoire/RepertoireRunUnits.vue'
import RunHeader from '@/components/training/RunHeader.vue'
import RunRecap from '@/components/training/RunRecap.vue'
import { useTimeboxedRun } from '@/composables/training/useTimeboxedRun'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'
import { subjectPath } from '@/utils/training'

definePage({ meta: { auth: 'required' } })

/** After a solved puzzle, the next one comes by itself (speed matters); after a miss, the solution first. */
const AUTO_NEXT_MS = 500

const route = useRoute()
const $q = useQuasar()
const store = useTrainingStore()
const runner = useTimeboxedRun()
const loading = ref(false)
const error = ref('')

/** A new object for each item: the player starts a new game. */
const puzzle = computed(() =>
  runner.item.value?.type === 'woodpecker_puzzle'
    ? runner.item.value.data.puzzle
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
    message: isRepertoire.value
      ? 'L’unité en cours ne sera pas comptée, sauf si vous y avez déjà fait une erreur.'
      : 'Le puzzle en cours ne sera pas compté.',
    cancel: true
  }).onOk(() => runner.stop().catch(e => (error.value = apiErrorMessage(e))))
}

// The run is over: nothing in progress any more for the rest of the app.
watch(
  () => runner.phase.value,
  phase => {
    if (phase === 'ended' && store.current?.id === runner.run.value?.id)
      store.current = null
  }
)

onMounted(async () => {
  loading.value = true
  try {
    await runner.resume(String(route.params.id))
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: 'Séance introuvable.' })
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.training-page {
  max-width: 700px;
  margin: 0 auto;
}
</style>
