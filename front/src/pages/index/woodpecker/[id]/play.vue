<template>
  <q-page padding>
    <CycleRecap
      v-if="store.recap"
      class="recap q-mb-md"
      :completed="store.recap.completed"
      :previous="store.recap.previous"
      :set-completed="store.recap.setCompleted"
    />

    <PuzzlePlayer
      v-if="!blocked"
      :puzzle="store.attempt?.puzzle ?? null"
      :loading="loading"
      after-mistake="showSolution"
      module="woodpecker"
      :xp="store.result ? store.result.xp : undefined"
      :actions="actions"
      skippable
      replaceable
      @resolve="onResolve"
      @skip="onSkip"
      @replace="onReplace"
    >
      <template #header>
        <div class="row items-center q-gutter-sm">
          <q-btn flat dense icon="arrow_back" :to="`/woodpecker/${id}`" />
          <div class="text-h6">{{ set?.name ?? 'Woodpecker' }}</div>
        </div>
        <div v-if="set?.current" data-testid="woodpecker-progress">
          <div class="text-subtitle2">
            Cycle {{ set.current.number }} / {{ set.cycleCount
            }}<span v-if="set.current.run > 1">
              (essai {{ set.current.run }})</span
            >
            · {{ set.current.played }} / {{ set.puzzleCount }}
          </div>
          <q-linear-progress
            :value="set.current.played / set.puzzleCount"
            class="q-my-xs"
          />
          <div
            class="text-caption"
            :class="paceInfo?.overdue ? 'text-negative' : 'text-grey'"
            >{{ paceInfo?.text }}</div
          >
        </div>
        <q-banner v-if="error" rounded class="bg-negative text-white">{{
          error
        }}</q-banner>
      </template>

      <template #chips>
        <span class="play-chip lt-md"
          >Un seul essai<q-tooltip
            >En cas d’erreur, la solution s’affiche.</q-tooltip
          ></span
        >
      </template>

      <template #result>
        <q-btn
          v-if="store.attempt"
          flat
          dense
          no-caps
          icon="open_in_new"
          label="Partie d'origine"
          class="self-start"
          :href="store.attempt.puzzle.gameUrl"
          target="_blank"
          rel="noopener noreferrer"
        />
      </template>
    </PuzzlePlayer>

    <div
      v-else
      class="column items-center q-gutter-md q-pa-lg"
      data-testid="woodpecker-blocked"
    >
      <div class="text-h6">{{ blocked }}</div>
      <q-btn
        color="primary"
        no-caps
        label="Voir le set"
        :to="`/woodpecker/${id}`"
        data-testid="woodpecker-set-link"
      />
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import PuzzlePlayer from '@/components/puzzle/PuzzlePlayer.vue'
import CycleRecap from '@/components/woodpecker/CycleRecap.vue'
import { useGamificationStore } from '@/stores/gamification'
import { useTrainingStore } from '@/stores/training'
import { useWoodpeckerStore } from '@/stores/woodpecker'
import { woodpeckerApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import { pace } from '@/utils/woodpeckerPace'

definePage({ meta: { auth: 'required' } })

const route = useRoute()
const store = useWoodpeckerStore()
const training = useTrainingStore()
const gamification = useGamificationStore()
const id = computed(() => String(route.params.id))
const set = computed(() => (store.set?.id === id.value ? store.set : null))
const loading = ref(false)
const error = ref('')
/** Why no puzzle can be played now (paused, resting, finished), or ''. */
const blocked = ref('')
/** The submission in flight: "Passer" waits for it before asking for the next puzzle. */
let submitting = Promise.resolve()

/**
 * The result sheet's button, once the submission answered (or failed); the day's first puzzle
 * shows the streak celebration first.
 */
const actions = computed(() => [
  {
    label: store.recap ? 'Continuer' : 'Puzzle suivant →',
    primary: true,
    disable: !store.result && !error.value,
    testid: 'woodpecker-next',
    onClick: async () => {
      await gamification.celebrateStreak({ afterExercise: !!store.result })
      await next()
    }
  }
])

const paceInfo = computed(() =>
  set.value?.current
    ? pace({
        remaining: set.value.puzzleCount - set.value.current.played,
        deadlineAt: set.value.current.deadlineAt,
        timeZone: set.value.timezone
      })
    : null
)

/**
 * The outcome is known (first mistake: the solution then plays; or clean end).
 *
 * @param {string} _outcome
 * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
 */
function onResolve(_outcome, report) {
  submitting = store
    .submit(report)
    .then(() => {})
    .catch(e => {
      error.value = apiErrorMessage(e, {
        409: 'Ce cycle est terminé ou perdu : rechargez le set.',
        404: 'Ce puzzle est introuvable.'
      })
    })
}

/** "Passer" confirmed: failed for this cycle; once recorded, straight to the next puzzle. */
async function onSkip() {
  loading.value = true
  await submitting
  if (error.value) {
    loading.value = false
    return
  }
  await gamification.celebrateStreak({ afterExercise: !!store.result })
  // The skip may have ended the cycle: its recap stays above the next screen.
  const recap = store.recap
  await next()
  if (recap) store.recap = recap
}

/** "Remplacer" confirmed: another puzzle takes its place in the set, served at once. */
async function onReplace() {
  const current = store.attempt
  if (!current) return
  loading.value = true
  error.value = ''
  try {
    await woodpeckerApi.replacePuzzle(id.value, current.puzzle.id)
  } catch (e) {
    error.value = apiErrorMessage(e, {
      409: 'Ce set est terminé : son contenu ne change plus.',
      422: 'Plus aucun autre puzzle ne correspond à ce set.'
    })
    loading.value = false
    return
  }
  await next()
}

async function next() {
  loading.value = true
  error.value = ''
  blocked.value = ''
  try {
    await store.next(id.value)
  } catch (e) {
    if (e?.response?.status === 409) {
      await store.fetchSet(id.value).catch(() => {})
      await training.fetchCurrent().catch(() => {})
      blocked.value = blockedReason()
    } else {
      error.value = apiErrorMessage(e, { 404: 'Set introuvable.' })
    }
  } finally {
    loading.value = false
  }
}

function blockedReason() {
  const s = set.value
  if (!s) return 'Ce set ne peut pas être joué.'
  if (training.current?.subjectId === s.id)
    return 'Une séance chronométrée est en cours sur ce set.'
  if (s.mode === 'light') return 'Ce set se joue en séances chronométrées.'
  if (s.status === 'paused') return 'Ce set est en pause.'
  if (s.status === 'completed') return 'Set terminé, bravo !'
  if (s.status === 'abandoned') return 'Ce set a été abandonné.'
  if (s.current?.status === 'resting')
    return `Repos : prochain cycle le ${formatDate(s.current.availableAt)}.`
  return 'Ce set ne peut pas être joué maintenant.'
}

onMounted(next)
</script>

<style scoped>
.recap {
  max-width: 1000px;
  margin-left: auto;
  margin-right: auto;
}
</style>
