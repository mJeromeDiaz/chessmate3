<template>
  <q-page padding>
    <div class="evaluation-page q-gutter-y-md">
      <div class="text-h5">{{ $t('evaluation.page.title') }}</div>
      <p class="text-body2 text-grey-8">
        {{
          $t('evaluation.page.lead', {
            advantage: overview ? pawns(overview.rules.advantageCp) : '…',
            winning: overview ? pawns(overview.rules.winningCp) : '…'
          })
        }}
      </p>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <div v-if="!overview && !error" class="row justify-center q-pa-lg">
        <q-spinner size="2em" />
      </div>

      <q-card v-if="overview" flat bordered data-testid="evaluation-settings">
        <q-card-section class="q-gutter-y-sm">
          <div class="text-subtitle1">{{ $t('evaluation.page.new') }}</div>
          <p
            class="text-caption text-grey q-mb-none"
            data-testid="evaluation-results"
            >{{ resultsText(overview.results) }}</p
          >

          <div class="text-subtitle2">{{
            $t('evaluation.page.level', { elo })
          }}</div>
          <q-slider
            v-model="elo"
            :min="overview.rules.minElo"
            :max="overview.rules.maxElo"
            :step="100"
            snap
            data-testid="evaluation-elo"
          />
          <div class="text-subtitle2">{{ $t('evaluation.page.side') }}</div>
          <q-btn-toggle
            v-model="side"
            no-caps
            unelevated
            toggle-color="primary"
            :options="sideOptions"
            data-testid="evaluation-side"
          />
          <p
            v-if="!available"
            class="text-caption text-warning q-mb-none"
            data-testid="evaluation-short"
          >
            {{ $t('evaluation.page.empty') }}
          </p>
          <RunLauncher
            v-if="subjectId"
            module="evaluation"
            :subject-id="subjectId"
            :config="{ elo, side }"
            :durations="overview.rules.runMinutes"
            :default-minutes="overview.rules.defaultMinutes"
            :disable="!available"
          />
        </q-card-section>
      </q-card>
    </div>
  </q-page>
</template>

<script setup>
/**
 * Position evaluation (docs/EVALUATION.md): the run's settings (length, level, side to move) and
 * the results so far. The rules (lengths, level bounds) and the catalogue's size come from the API.
 */
import { computed, onMounted, ref } from 'vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { evaluationApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import {
  SIDE_OPTIONS,
  pawns,
  positionsFor,
  resultsText
} from '@/utils/evaluation'

definePage({
  meta: {
    auth: 'required',
    header: {
      title: 'evaluation.page.title',
      subtitle: 'layout.header.kickers.free'
    }
  }
})

const auth = useAuthStore()
/** @type {import('vue').Ref<any>} GET /evaluation */
const overview = ref(null)
const error = ref('')
const elo = ref(1500)
const side = ref('both')

const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)

const sideOptions = computed(() =>
  SIDE_OPTIONS.map(o => ({
    ...o,
    label: `${o.label} (${positionsFor(overview.value.positions, o.value)})`
  }))
)
const available = computed(() =>
  overview.value ? positionsFor(overview.value.positions, side.value) : 0
)

onMounted(async () => {
  if (!subjectId.value) auth.fetchProfile().catch(() => {})
  try {
    const value = await evaluationApi.overview()
    overview.value = value
    const r = value.rules
    elo.value = Math.min(Math.max(elo.value, r.minElo), r.maxElo)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
})
</script>

<style scoped lang="scss">
.evaluation-page {
  max-width: 800px;
  margin: 0 auto;
}
</style>
