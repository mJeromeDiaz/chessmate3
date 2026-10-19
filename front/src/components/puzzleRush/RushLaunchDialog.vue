<template>
  <q-dialog
    :model-value="modelValue"
    @update:model-value="value => emit('update:modelValue', value)"
  >
    <q-card style="min-width: min(92vw, 460px)" data-testid="rush-dialog">
      <q-card-section>
        <div class="text-h6">{{ $t('puzzleRush.launch.title') }}</div>
        <div class="text-caption cm-muted">
          {{ $t('puzzleRush.launch.lead') }}
          {{
            themes.length
              ? $t('puzzle.run.themes', {
                  list: themes.map(store.themeLabel).join(', ')
                })
              : $t('puzzle.run.allThemes')
          }}
        </div>
      </q-card-section>
      <q-card-section>
        <RunLauncher
          v-if="subjectId"
          module="puzzle_rush"
          :subject-id="subjectId"
          :config="{ themes }"
          :durations="choices.minutes"
          :default-minutes="choices.default"
          :label="$t('puzzleRush.launch.start')"
          icon="bolt"
        />
        <q-spinner v-else />
      </q-card-section>
      <q-card-section v-if="records.length" class="q-pt-none">
        <div class="text-subtitle2">{{ $t('puzzleRush.launch.records') }}</div>
        <div
          v-for="record in records"
          :key="record.budgetSeconds"
          class="text-body2"
          :data-testid="`rush-record-${record.budgetSeconds}`"
        >
          {{ recordText(record) }}
        </div>
        <div class="text-caption cm-muted">{{
          $t('puzzleRush.launch.rankedOnly')
        }}</div>
      </q-card-section>
      <q-card-actions align="right">
        <q-btn v-close-popup flat no-caps :label="$t('common.actions.close')" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
/**
 * Launches a puzzle rush (docs/PUZZLE_RUSH.md) with the themes of the play page's filter: 2, 3 or
 * 5 minutes (the API's lengths), and the records of each length. The subject of the run is the
 * user.
 */
import { computed, ref, watch } from 'vue'
import RunLauncher from '@/components/training/RunLauncher.vue'
import { puzzleRushApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { usePuzzleStore } from '@/stores/puzzle'
import { rushMinutes } from '@/utils/puzzleRush'
import { t } from '@/i18n'

const props = defineProps({
  modelValue: { type: Boolean, default: false }
})

const emit = defineEmits({ 'update:modelValue': null })

const auth = useAuthStore()
const store = usePuzzleStore()
const subjectId = computed(() => /** @type {any} */ (auth.profile)?.id ?? null)
const themes = computed(() => [...store.filters.themes])

/** @type {import('vue').Ref<import('@/utils/puzzleRush').RushRules|null>} */
const rules = ref(null)
/** @type {import('vue').Ref<import('@/utils/puzzleRush').RushRecord[]>} */
const records = ref([])
const choices = computed(() => rushMinutes(rules.value))

/**
 * "3 min : 14 points", or no record yet for that length.
 *
 * @param {import('@/utils/puzzleRush').RushRecord} record
 */
function recordText(record) {
  return t('puzzleRush.launch.recordLine', {
    minutes: record.budgetSeconds / 60,
    best:
      record.best === null
        ? t('puzzleRush.launch.noRecord')
        : t('puzzleRush.launch.points', { n: record.best })
  })
}

// Read each time it opens: a rush just played may have set a record.
watch(
  () => props.modelValue,
  open => {
    if (!open) return
    if (!subjectId.value) auth.fetchProfile().catch(() => {})
    puzzleRushApi
      .overview()
      .then(overview => {
        rules.value = overview.rules
        records.value = overview.records
      })
      .catch(() => {})
  },
  { immediate: true }
)
</script>
