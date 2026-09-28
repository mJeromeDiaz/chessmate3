<template>
  <q-page padding>
    <div class="puzzle-page">
      <div class="puzzle-page__board">
        <ChessBoard
          v-if="puzzle.puzzle.value"
          ref="board"
          :fen="puzzle.fen.value"
          :orientation="puzzle.orientation.value"
          :movable-color="puzzle.movableColor.value"
          :highlights="puzzle.highlights.value"
          :arrows="puzzle.arrows.value"
          @move="onMove"
        />
        <div v-else-if="loading" class="flex flex-center q-pa-xl">
          <q-spinner size="3em" />
        </div>
      </div>

      <div class="puzzle-page__panel column q-gutter-md">
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
        </div>

        <q-banner
          v-if="store.rating?.lichessImportAvailable"
          rounded
          class="bg-blue-1 text-dark"
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
        </q-banner>

        <template v-if="attempt">
          <div class="text-subtitle1" data-testid="puzzle-status">{{
            statusText
          }}</div>
          <div class="text-caption text-grey">
            {{ attempt.rated ? 'Partie classée' : 'Rejeu non classé' }} · puzzle
            {{ attempt.puzzle.rating }}
          </div>

          <div v-if="puzzle.phase.value !== 'complete'" class="row q-gutter-sm">
            <q-btn
              outline
              no-caps
              icon="lightbulb"
              :label="
                puzzle.hintShown.value === 0 ? 'Indice' : 'Indice suivant'
              "
              :disable="
                puzzle.phase.value !== 'playing' || puzzle.hintShown.value >= 2
              "
              data-testid="puzzle-hint"
              @click="puzzle.hint()"
            />
            <q-btn
              outline
              no-caps
              icon="visibility"
              label="Voir la solution"
              :disable="puzzle.phase.value === 'idle'"
              data-testid="puzzle-solution"
              @click="puzzle.showSolution()"
            />
          </div>

          <div v-else class="column q-gutter-sm" data-testid="puzzle-result">
            <!-- The server's verdict, not the local one: shown once the submission answered. -->
            <div v-if="!store.result" class="row items-center q-gutter-sm">
              <q-spinner size="1.5em" />
              <span>Enregistrement du résultat…</span>
            </div>
            <div
              v-else
              class="text-h6"
              :class="
                store.result.status === 'solved'
                  ? 'text-positive'
                  : 'text-negative'
              "
            >
              {{ store.result.status === 'solved' ? 'Réussi !' : 'Échoué' }}
              <span v-if="store.result.ratingDelta !== null">
                ({{ formatRatingDelta(store.result.ratingDelta) }})
              </span>
            </div>
            <div class="row q-gutter-sm">
              <!-- Disabled until the submission answered: asking earlier would hand back the
                   same, still pending, attempt. -->
              <q-btn
                color="primary"
                no-caps
                icon="skip_next"
                label="Suivant"
                :disable="!store.result && !error"
                data-testid="puzzle-next"
                @click="next"
              />
              <q-btn
                outline
                no-caps
                icon="replay"
                label="Rejouer"
                @click="replay(attempt.puzzle.id)"
              />
              <q-btn
                flat
                no-caps
                icon="open_in_new"
                label="Partie d'origine"
                :href="attempt.puzzle.gameUrl"
                target="_blank"
                rel="noopener noreferrer"
              />
            </div>
          </div>
        </template>
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ChessBoard from '@/components/chess/ChessBoard.vue'
import RatingBadge from '@/components/puzzle/RatingBadge.vue'
import { usePuzzle } from '@/composables/puzzle/usePuzzle'
import { usePuzzleStore } from '@/stores/puzzle'
import { apiErrorMessage } from '@/utils/apiError'
import { formatRatingDelta } from '@/utils/format'

definePage({ meta: { auth: 'required' } })

const DIFFICULTIES = [
  { label: 'Plus facile', value: 'easier' },
  { label: 'Normal', value: 'normal' },
  { label: 'Plus difficile', value: 'harder' }
]

const store = usePuzzleStore()
const route = useRoute()
const router = useRouter()
const board = ref(null)
const loading = ref(false)
const importing = ref(false)
const error = ref('')

const attempt = computed(() => store.attempt)
const replayId = computed(() =>
  typeof route.query.replay === 'string' ? route.query.replay : null
)

const puzzle = usePuzzle({
  // Submitted as soon as the rated outcome is known: a reload after a mistake can't erase it.
  onResolve: (_outcome, report) => {
    store.submit(report).catch(e => {
      error.value = apiErrorMessage(e, {
        409: 'Ce puzzle a déjà été soumis.',
        404: 'Cette tentative est introuvable.'
      })
    })
  }
})

const statusText = computed(() => {
  const side = puzzle.orientation.value === 'white' ? 'les Blancs' : 'les Noirs'
  switch (puzzle.phase.value) {
    case 'intro':
      return puzzle.solutionShown.value
        ? 'Solution…'
        : 'Au tour de l’adversaire…'
    case 'playing':
      return puzzle.failed.value
        ? 'Ce n’est pas le bon coup. Cherchez encore !'
        : `Trouvez le meilleur coup pour ${side}.`
    case 'complete':
      return puzzle.failed.value ? 'Puzzle terminé.' : 'Bravo !'
    default:
      return ''
  }
})

/** @param {{uci: string}} move */
async function onMove(move) {
  const verdict = puzzle.play(move.uci)
  if (verdict === 'correct') return
  if (verdict === 'wrong') await board.value?.shake()
  // Back to the position before the move (the board already shows it played).
  await board.value?.setPosition(puzzle.fen.value, true)
}

/** @param {() => Promise<object>} request */
async function begin(request) {
  loading.value = true
  error.value = ''
  puzzle.dispose()
  try {
    const started = await request()
    puzzle.load(started.puzzle)
  } catch (e) {
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

onBeforeUnmount(() => puzzle.dispose())
</script>

<style scoped>
.puzzle-page {
  display: grid;
  gap: 24px;
  grid-template-columns: minmax(0, 560px) minmax(260px, 1fr);
  align-items: start;
  max-width: 1000px;
  margin: 0 auto;
}
@media (max-width: 800px) {
  .puzzle-page {
    grid-template-columns: 1fr;
  }
}
</style>
