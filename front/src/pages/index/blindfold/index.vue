<template>
  <q-page padding>
    <div class="blindfold-page q-gutter-y-md">
      <div class="text-h5">Jeu à l’aveugle</div>
      <p class="text-body2 text-grey-8">
        Des puzzles résolus de mémoire : la position s’affiche le temps choisi,
        disparaît
        {{ puzzles ? `${puzzles.rules.hiddenSeconds} s` : '…' }}, puis tu joues
        la solution sur un échiquier vide (coordonnées affichées) : clique la
        case de départ, puis la case d’arrivée.
        <template v-if="puzzles">{{ peeksText(puzzles.rules.peeks) }}</template>
        Aucun effet sur ton classement puzzles.
      </p>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <div v-if="!puzzles && !error" class="row justify-center q-pa-lg">
        <q-spinner size="2em" />
      </div>

      <q-card v-if="puzzles" flat bordered data-testid="blindfold-puzzles">
        <q-card-section class="q-gutter-y-sm">
          <div class="text-subtitle1">Nouvelle séance</div>
          <p
            class="text-caption text-grey q-mb-none"
            data-testid="blindfold-puzzles-total"
            >{{ puzzleCountsText(puzzles.total) }}</p
          >

          <div class="text-subtitle2">Niveau</div>
          <q-btn-toggle
            v-model="level"
            no-caps
            unelevated
            toggle-color="primary"
            :options="levelOptions"
            data-testid="blindfold-level"
          />
          <div class="text-subtitle2">Longueur de la solution</div>
          <q-btn-toggle
            v-model="length"
            no-caps
            unelevated
            toggle-color="primary"
            :options="lengthOptions"
            data-testid="blindfold-length"
          />
          <div class="text-subtitle2">Temps pour mémoriser</div>
          <q-btn-toggle
            v-model="visibleSeconds"
            no-caps
            unelevated
            toggle-color="primary"
            :options="visibleOptions"
            data-testid="blindfold-visible"
          />
          <RunLauncher
            v-if="subjectId"
            module="blindfold"
            :subject-id="subjectId"
            :config="{ level, length, visibleSeconds }"
          />
        </q-card-section>
        <q-separator />
        <q-card-section>
          <div class="text-subtitle2 q-mb-xs">Tes résultats par niveau</div>
          <div
            v-for="l in puzzles.rules.levels"
            :key="l.key"
            class="text-body2"
            :data-testid="`blindfold-stats-${l.key}`"
          >
            <b>{{ LEVELS[l.key] ?? l.key }}</b> :
            {{ puzzleCountsText(puzzles.byLevel[l.key]) }}
          </div>
        </q-card-section>
      </q-card>
    </div>
  </q-page>
</template>

<script setup>
/**
 * Blindfold puzzles (docs/BLINDFOLD.md), open to everyone: level, length, time to memorize, a
 * timed run, the results by level. The rules come from the API.
 */
import { computed, onMounted, ref } from 'vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { blindfoldApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import {
  LEVELS,
  lengthText,
  levelText,
  puzzleCountsText
} from '@/utils/blindfold'

definePage({ meta: { auth: 'required' } })

const auth = useAuthStore()
/** @type {import('vue').Ref<any>} GET /blindfold/puzzles */
const puzzles = ref(null)
const error = ref('')
const level = ref('easy')
const length = ref(2)
const visibleSeconds = ref(10)

const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)

/** @type {import('vue').ComputedRef<import('@/utils/blindfold').PuzzleRules|null>} */
const rules = computed(() => puzzles.value?.rules ?? null)
const levelOptions = computed(() =>
  (rules.value?.levels ?? []).map(l => ({ value: l.key, label: levelText(l) }))
)
const lengthOptions = computed(() => {
  const lengths = rules.value?.lengths ?? []
  return lengths.map(l => ({ value: l, label: lengthText(l, lengths) }))
})
const visibleOptions = computed(() =>
  (rules.value?.visibleSeconds ?? []).map(s => ({ value: s, label: `${s} s` }))
)

/** @param {number} peeks */
function peeksText(peeks) {
  if (!peeks) return 'Une erreur, et le puzzle est raté.'
  return peeks === 1
    ? 'Après une erreur, un coup d’œil sur la position ; une deuxième erreur, et le puzzle est raté.'
    : `Après une erreur, un coup d’œil sur la position (${peeks} au plus).`
}

onMounted(async () => {
  if (!subjectId.value) auth.fetchProfile().catch(() => {})
  try {
    const value = await blindfoldApi.puzzles()
    puzzles.value = value
    const r = value.rules
    if (!r.visibleSeconds.includes(visibleSeconds.value))
      visibleSeconds.value = r.visibleSeconds[0]
    if (!r.lengths.includes(length.value)) length.value = r.lengths[0]
    if (!r.levels.some((/** @type {{key: string}} */ l) => l.key === level.value))
      level.value = r.levels[0].key
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.blindfold-page {
  max-width: 800px;
  margin: 0 auto;
}
</style>
