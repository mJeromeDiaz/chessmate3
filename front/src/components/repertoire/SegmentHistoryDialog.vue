<template>
  <q-dialog
    :model-value="modelValue"
    @update:model-value="value => emit('update:modelValue', value)"
  >
    <q-card style="min-width: min(92vw, 560px)" data-testid="segment-history">
      <q-card-section>
        <div class="text-h6">{{ title }}</div>
        <div v-if="history?.path.length" class="text-caption cm-muted">{{
          numberedMoves(history.path)
        }}</div>
        <div v-if="history?.archived" class="text-caption cm-muted">{{
          $t('repertoire.segmentHistory.archived')
        }}</div>
      </q-card-section>
      <q-card-section v-if="loading" class="flex flex-center"
        ><q-spinner
      /></q-card-section>
      <q-card-section v-else-if="error" class="text-negative">{{
        error
      }}</q-card-section>
      <q-list v-else-if="history?.presentations.length" dense separator>
        <q-item
          v-for="p in history.presentations"
          :key="p.id"
          data-testid="segment-history-row"
        >
          <q-item-section avatar>
            <q-icon
              :name="
                p.status === 'succeeded'
                  ? 'check_circle'
                  : p.status === 'failed'
                    ? 'cancel'
                    : 'pause_circle'
              "
              :color="
                p.status === 'succeeded'
                  ? 'positive'
                  : p.status === 'failed'
                    ? 'negative'
                    : 'grey'
              "
            />
          </q-item-section>
          <q-item-section>
            <q-item-label
              >{{ formatDate(p.finishedAt) }} ·
              {{ statusText(p.status) }}</q-item-label
            >
            <q-item-label caption>
              {{
                p.unit === 'line'
                  ? $t('repertoire.segmentHistory.line')
                  : $t('repertoire.segmentHistory.segment')
              }}
              <template v-if="p.rank > 1">{{
                $t('repertoire.segmentHistory.retry', { n: p.rank })
              }}</template>
              <template v-if="p.beforeMerge">{{
                $t('repertoire.segmentHistory.beforeMerge')
              }}</template>
              <template v-if="p.durationMs !== null">
                · {{ formatDuration(p.durationMs) }}</template
              >
            </q-item-label>
          </q-item-section>
        </q-item>
      </q-list>
      <q-card-section v-else class="text-grey">{{
        $t('repertoire.segmentHistory.never')
      }}</q-card-section>
      <q-card-actions align="right">
        <q-btn v-close-popup flat no-caps :label="$t('common.actions.close')" />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
/**
 * The last presentations of a segment (GET /repertoires/{id}/segments/{segmentId}): retries
 * and the segments merged into it included.
 */
import { computed, ref, watch } from 'vue'
import { repertoireApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate, formatDuration } from '@/utils/format'
import { t } from '@/i18n'
import { labelText, numberedMoves, statusText } from '@/utils/repertoireTest'

const props = defineProps({
  modelValue: { type: Boolean, default: false },
  repertoireId: { type: String, required: true },
  segmentId: { type: String, default: null }
})

const emit = defineEmits({ 'update:modelValue': null })

const history = ref(null)
const loading = ref(false)
const error = ref('')

const title = computed(() =>
  history.value?.label
    ? labelText(history.value.label)
    : history.value?.presentations[0]
      ? labelText(history.value.presentations[0].label)
      : t('repertoire.segmentHistory.title')
)

watch(
  () => [props.modelValue, props.segmentId],
  async ([open, segmentId]) => {
    if (!open || !segmentId) return
    loading.value = true
    error.value = ''
    history.value = null
    try {
      history.value = await repertoireApi.segmentHistory(
        props.repertoireId,
        String(segmentId)
      )
    } catch (e) {
      error.value = apiErrorMessage(e)
    } finally {
      loading.value = false
    }
  },
  { immediate: true }
)
</script>
