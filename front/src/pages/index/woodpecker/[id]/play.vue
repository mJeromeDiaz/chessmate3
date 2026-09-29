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
      @resolve="onResolve"
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

      <template #info>
        <div class="text-caption text-grey"
          >Un seul essai par puzzle dans le cycle : en cas d’erreur, la solution
          s’affiche.</div
        >
      </template>

      <template #result>
        <div v-if="!store.result" class="row items-center q-gutter-sm">
          <q-spinner size="1.5em" />
          <span>Enregistrement…</span>
        </div>
        <div
          v-else
          class="text-h6"
          :class="
            store.result.status === 'solved' ? 'text-positive' : 'text-negative'
          "
        >
          {{ store.result.status === 'solved' ? 'Réussi !' : 'Échoué' }}
        </div>
        <div class="row q-gutter-sm">
          <q-btn
            color="primary"
            no-caps
            icon="skip_next"
            :label="store.recap ? 'Continuer' : 'Suivant'"
            :disable="!store.result && !error"
            data-testid="woodpecker-next"
            @click="next"
          />
          <q-btn
            v-if="store.attempt"
            flat
            no-caps
            icon="open_in_new"
            label="Partie d'origine"
            :href="store.attempt.puzzle.gameUrl"
            target="_blank"
            rel="noopener noreferrer"
          />
        </div>
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
import { useWoodpeckerStore } from '@/stores/woodpecker'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate } from '@/utils/format'
import { pace } from '@/utils/woodpeckerPace'

definePage({ meta: { auth: 'required' } })

const route = useRoute()
const store = useWoodpeckerStore()
const id = computed(() => String(route.params.id))
const set = computed(() => (store.set?.id === id.value ? store.set : null))
const loading = ref(false)
const error = ref('')
/** Why no puzzle can be played now (paused, resting, finished), or ''. */
const blocked = ref('')

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
  store.submit(report).catch(e => {
    error.value = apiErrorMessage(e, {
      409: 'Ce cycle est terminé ou perdu : rechargez le set.',
      404: 'Ce puzzle est introuvable.'
    })
  })
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
