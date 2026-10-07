<template>
  <q-page padding>
    <PuzzlePlayer
      :puzzle="attempt?.puzzle ?? null"
      :loading="loading"
      module="puzzles"
      :xp="store.result ? store.result.xp : undefined"
      :rating-delta="store.result?.ratingDelta ?? null"
      :actions="actions"
      @resolve="onResolve"
    >
      <template #header>
        <div class="row items-center q-gutter-sm">
          <div class="text-h6">Puzzles</div>
          <RatingBadge />
          <q-space />
          <q-btn
            flat
            dense
            no-caps
            icon="category"
            label="Thèmes"
            to="/puzzle/themes"
          />
          <q-btn
            flat
            dense
            no-caps
            icon="history"
            label="Historique"
            to="/puzzle/history"
          />
          <q-btn
            flat
            dense
            no-caps
            icon="timer"
            label="Séance chronométrée"
            data-testid="puzzle-run-open"
            @click="runDialog = true"
          />
        </div>
        <PuzzleRunDialog v-model="runDialog" />

        <q-banner
          v-if="store.rating?.lichessImportAvailable"
          rounded
          class="cm-banner--info"
        >
          Vous avez lié votre compte Lichess : démarrer avec votre classement
          puzzle Lichess ?
          <template #action>
            <q-btn
              flat
              no-caps
              color="primary"
              label="Importer"
              :loading="importing"
              @click="importLichess"
            />
          </template>
        </q-banner>

        <div v-if="!replayId" class="row items-center q-gutter-sm">
          <q-btn-toggle
            :model-value="store.filters.difficulty"
            no-caps
            dense
            toggle-color="primary"
            :options="DIFFICULTIES"
            @update:model-value="store.setDifficulty"
          />
          <q-chip
            v-for="key in store.filters.themes"
            :key="key"
            dense
            removable
            data-testid="puzzle-theme-filter"
            @remove="
              store.setThemes(store.filters.themes.filter(k => k !== key))
            "
          >
            {{ store.themeLabel(key) }}
          </q-chip>
        </div>

        <q-banner
          v-if="error"
          rounded
          class="bg-negative text-white"
          data-testid="puzzle-error"
        >
          {{ error }}
          <template v-if="heldBy" #action>
            <q-btn
              flat
              no-caps
              color="white"
              label="Rejoindre la séance"
              :to="`/training/${heldBy}`"
              data-testid="puzzle-join-run"
            />
          </template>
        </q-banner>
      </template>

      <template #info>
        <div v-if="attempt" class="text-caption text-grey">
          {{ attempt.rated ? 'Partie classée' : 'Rejeu non classé' }} · puzzle
          {{ attempt.puzzle.rating }}
        </div>
      </template>

      <template #result>
        <q-btn
          v-if="attempt"
          flat
          dense
          no-caps
          icon="open_in_new"
          label="Partie d'origine"
          class="self-start"
          :href="attempt.puzzle.gameUrl"
          target="_blank"
          rel="noopener noreferrer"
        />
      </template>
    </PuzzlePlayer>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PuzzlePlayer from '@/components/puzzle/PuzzlePlayer.vue'
import PuzzleRunDialog from '@/components/puzzle/PuzzleRunDialog.vue'
import RatingBadge from '@/components/puzzle/RatingBadge.vue'
import { useGamificationStore } from '@/stores/gamification'
import { usePuzzleStore } from '@/stores/puzzle'
import { useTrainingStore } from '@/stores/training'
import { apiErrorMessage } from '@/utils/apiError'

definePage({ meta: { auth: 'required' } })

const DIFFICULTIES = [
  { label: 'Plus facile', value: 'easier' },
  { label: 'Normal', value: 'normal' },
  { label: 'Plus difficile', value: 'harder' }
]

const store = usePuzzleStore()
const route = useRoute()
const router = useRouter()
const loading = ref(false)
const importing = ref(false)
const error = ref('')
const runDialog = ref(false)
/** The timed run holding the pending puzzle (free play answered 409), to join it. */
const heldBy = ref(/** @type {string|null} */ (null))
const training = useTrainingStore()

/** Free play refused: the pending puzzle is being played in a timed run. */
const HELD_BY_RUN =
  'Votre puzzle en cours est joué dans une séance chronométrée.'

/**
 * A 409 of free play means a timed run holds the pending puzzle: point to it.
 *
 * @param {unknown} e
 * @returns {Promise<boolean>} whether it was that
 */
async function heldByRun(e) {
  const response = /** @type {any} */ (e)?.response
  // The API's 409 detail tells it apart from "already submitted".
  if (
    response?.status !== 409 ||
    !/timed run/.test(response.data?.detail ?? '')
  )
    return false
  const run = await training.fetchCurrent().catch(() => null)
  if (!run) return false
  heldBy.value = run.id
  error.value = HELD_BY_RUN
  return true
}

const attempt = computed(() => store.attempt)

const gamification = useGamificationStore()

/**
 * After the result sheet, the day's first puzzle shows the streak celebration first.
 *
 * @param {() => void} action
 */
function afterStreak(action) {
  return async () => {
    await gamification.celebrateStreak({ afterExercise: !!store.result })
    action()
  }
}

/**
 * The result sheet's buttons: the next puzzle once the submission answered (asking earlier would
 * hand back the same, still pending, attempt), and an unrated replay after a failure.
 */
const actions = computed(() => {
  const next_ = {
    label: replayId.value ? 'Retour aux puzzles' : 'Puzzle suivant →',
    primary: true,
    disable: !store.result && !error.value,
    testid: 'puzzle-next',
    onClick: afterStreak(next)
  }
  const current = attempt.value
  if (store.result?.status !== 'failed' || !current) return [next_]
  return [
    {
      label: 'Réessayer',
      testid: 'puzzle-retry',
      onClick: afterStreak(() => replay(current.puzzle.id))
    },
    next_
  ]
})
const replayId = computed(() =>
  typeof route.query.replay === 'string' ? route.query.replay : null
)

/**
 * Submitted as soon as the rated outcome is known: a reload after a mistake can't erase it.
 *
 * @param {string} _outcome
 * @param {{moves: string[], hintLevel: number, solutionShown: boolean}} report
 */
function onResolve(_outcome, report) {
  store.submit(report).catch(async e => {
    if (await heldByRun(e)) return
    error.value = apiErrorMessage(e, {
      409: 'Ce puzzle a déjà été soumis.',
      404: 'Cette tentative est introuvable.'
    })
  })
}

/** @param {() => Promise<object>} request */
async function begin(request) {
  loading.value = true
  error.value = ''
  heldBy.value = null
  try {
    await request()
  } catch (e) {
    if (await heldByRun(e)) return
    error.value = apiErrorMessage(e, {
      404: replayId.value
        ? 'Ce puzzle ne fait pas partie de votre historique.'
        : 'Aucun puzzle ne correspond à ces critères. Essayez d’autres thèmes.',
      422: 'Thème inconnu.'
    })
  } finally {
    loading.value = false
  }
}

function next() {
  if (replayId.value) {
    router.replace('/puzzle')
    return
  }
  begin(() => store.next())
}

/** @param {string} id */
function replay(id) {
  begin(() => store.replay(id))
}

async function importLichess() {
  importing.value = true
  try {
    await store.importLichessRating()
  } catch (e) {
    error.value = apiErrorMessage(e, {
      409: 'Votre classement est déjà établi.',
      422: 'Lichess n’a pas de classement puzzle pour ce compte.'
    })
  } finally {
    importing.value = false
  }
}

watch(replayId, id => (id ? replay(id) : begin(() => store.next())))

onMounted(() => {
  store.fetchThemes().catch(() => {})
  store.fetchRating().catch(() => {})
  if (replayId.value) replay(replayId.value)
  else begin(() => store.next())
})
</script>
