<template>
  <q-page padding>
    <div class="blindfold-page q-gutter-y-md">
      <div class="text-h5">{{ $t('blindfold.page.title') }}</div>
      <p class="text-body2 text-grey-8">
        {{
          $t('blindfold.page.lead', {
            hidden: puzzles
              ? $t('blindfold.page.hidden', { n: puzzles.rules.hiddenSeconds })
              : '…'
          })
        }}
        <template v-if="puzzles">{{ peeksText(puzzles.rules.peeks) }}</template>
        {{ $t('blindfold.page.unrated') }}
      </p>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <div v-if="!puzzles && !error" class="row justify-center q-pa-lg">
        <q-spinner size="2em" />
      </div>

      <q-card v-if="puzzles" flat bordered data-testid="blindfold-puzzles">
        <q-card-section class="q-gutter-y-sm">
          <div class="text-subtitle1">{{ $t('blindfold.page.new') }}</div>
          <p
            class="text-caption text-grey q-mb-none"
            data-testid="blindfold-puzzles-total"
            >{{ puzzleCountsText(puzzles.total) }}</p
          >

          <div class="text-subtitle2">{{ $t('blindfold.page.level') }}</div>
          <q-btn-toggle
            v-model="level"
            no-caps
            unelevated
            toggle-color="primary"
            :options="levelOptions"
            data-testid="blindfold-level"
          />
          <div class="text-subtitle2">{{ $t('blindfold.page.length') }}</div>
          <q-btn-toggle
            v-model="length"
            no-caps
            unelevated
            toggle-color="primary"
            :options="lengthOptions"
            data-testid="blindfold-length"
          />
          <div class="text-subtitle2">{{ $t('blindfold.page.memorize') }}</div>
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
          <div class="text-subtitle2 q-mb-xs">{{
            $t('blindfold.page.byLevel')
          }}</div>
          <div
            v-for="l in puzzles.rules.levels"
            :key="l.key"
            class="text-body2"
            :data-testid="`blindfold-stats-${l.key}`"
          >
            <b>{{
              $t('blindfold.page.levelRow', { level: LEVELS[l.key] ?? l.key })
            }}</b>
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
import { t } from '@/i18n'

definePage({
  meta: {
    auth: 'required',
    // Open to a guest (docs/GUEST.md).
    guestOpen: true,
    header: {
      title: 'blindfold.page.title',
      subtitle: 'layout.header.kickers.free'
    }
  }
})

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
  (rules.value?.visibleSeconds ?? []).map(s => ({
    value: s,
    label: t('blindfold.page.hidden', { n: s })
  }))
)

/** @param {number} peeks */
function peeksText(peeks) {
  if (!peeks) return t('blindfold.page.peeks.none')
  return peeks === 1
    ? t('blindfold.page.peeks.one')
    : t('blindfold.page.peeks.many', { n: peeks })
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
    if (
      !r.levels.some((/** @type {{key: string}} */ l) => l.key === level.value)
    )
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
