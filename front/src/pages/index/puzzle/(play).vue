<template>
  <q-page padding>
    <PuzzlePlayer
      :puzzle="attempt?.puzzle ?? null"
      :loading="loading"
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
      </template>

      <template #info>
        <div v-if="attempt" class="text-caption text-grey">
          {{ attempt.rated ? 'Partie classée' : 'Rejeu non classé' }} · puzzle
          {{ attempt.puzzle.rating }}
        </div>
      </template>

      <template #result>
        <!-- The server's verdict, not the local one: shown once the submission answered. -->
        <div v-if="!store.result" class="row items-center q-gutter-sm">
          <q-spinner size="1.5em" />
          <span>Enregistrement du résultat…</span>
        </div>
        <div
          v-else
          class="text-h6"
          :class="
            store.result.status === 'solved' ? 'text-positive' : 'text-negative'
          "
        >
          {{ store.result.status === 'solved' ? 'Réussi !' : 'Échoué' }}
          <span v-if="store.result.ratingDelta !== null">
            ({{ formatRatingDelta(store.result.ratingDelta) }})
          </span>
        </div>
        <div v-if="attempt" class="row q-gutter-sm">
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
      </template>
    </PuzzlePlayer>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import PuzzlePlayer from '@/components/puzzle/PuzzlePlayer.vue'
import RatingBadge from '@/components/puzzle/RatingBadge.vue'
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
const loading = ref(false)
const importing = ref(false)
const error = ref('')

const attempt = computed(() => store.attempt)
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
  store.submit(report).catch(e => {
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
  try {
    await request()
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
</script>
