<template>
  <q-page padding>
    <div class="history-page">
      <div class="row items-center q-gutter-sm q-mb-md">
        <div class="text-h6">Historique</div>
        <q-space />
        <q-btn-toggle
          v-model="result"
          no-caps
          dense
          toggle-color="primary"
          :options="[
            { label: 'Tous', value: null },
            { label: 'Réussis', value: 'solved' },
            { label: 'Échoués', value: 'failed' }
          ]"
        />
        <q-select
          v-model="theme"
          :options="themeOptions"
          emit-value
          map-options
          clearable
          dense
          outlined
          label="Thème"
          style="min-width: 200px"
        />
      </div>

      <q-banner v-if="error" rounded class="bg-negative text-white q-mb-md">{{
        error
      }}</q-banner>

      <q-table
        v-model:pagination="pagination"
        :rows="rows"
        :columns="columns"
        row-key="id"
        :loading="loading"
        flat
        bordered
        :rows-per-page-options="[20]"
        no-data-label="Aucune tentative."
        data-testid="history-table"
        @request="onRequest"
      >
        <template #body-cell-themes="props">
          <q-td :props="props">
            <span class="text-caption">
              {{ props.row.puzzle.themes.map(store.themeLabel).join(', ') }}
            </span>
          </q-td>
        </template>
        <template #body-cell-status="props">
          <q-td :props="props">
            <q-badge
              :color="props.row.status === 'solved' ? 'positive' : 'negative'"
            >
              {{ props.row.status === 'solved' ? 'Réussi' : 'Échoué' }}
            </q-badge>
            <span v-if="!props.row.rated" class="text-caption text-grey q-ml-xs"
              >non classé</span
            >
          </q-td>
        </template>
        <template #body-cell-actions="props">
          <q-td :props="props">
            <q-btn
              flat
              dense
              no-caps
              icon="replay"
              label="Rejouer"
              :to="{ path: '/puzzle', query: { replay: props.row.puzzle.id } }"
            />
          </q-td>
        </template>
      </q-table>
    </div>
  </q-page>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { usePuzzleStore } from '@/stores/puzzle'
import { puzzleApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate, formatDuration, formatRatingDelta } from '@/utils/format'

definePage({ meta: { auth: 'required' } })

const store = usePuzzleStore()
const rows = ref([])
const loading = ref(false)
const error = ref('')
const result = ref(null)
const theme = ref(null)
const pagination = ref({ page: 1, rowsPerPage: 20, rowsNumber: 0 })

const columns = [
  {
    name: 'date',
    label: 'Date',
    field: 'submittedAt',
    format: formatDate,
    align: 'left'
  },
  { name: 'themes', label: 'Thèmes', field: 'puzzle', align: 'left' },
  { name: 'puzzleRating', label: 'Puzzle', field: row => row.puzzle.rating },
  { name: 'status', label: 'Résultat', field: 'status', align: 'left' },
  {
    name: 'duration',
    label: 'Durée',
    field: 'durationMs',
    format: formatDuration
  },
  {
    name: 'delta',
    label: 'Classement',
    field: 'ratingDelta',
    format: formatRatingDelta
  },
  { name: 'actions', label: '', field: 'id' }
]

const themeOptions = computed(() =>
  store.themes.map(t => ({ label: t.labelFr, value: t.key }))
)

/** @param {{pagination: {page: number}}} request q-table server-side request */
async function onRequest({ pagination: { page } }) {
  loading.value = true
  error.value = ''
  try {
    const data = await puzzleApi.history({
      page,
      ...(result.value ? { result: result.value } : {}),
      ...(theme.value ? { theme: theme.value } : {})
    })
    rows.value = data.member
    pagination.value = {
      ...pagination.value,
      page,
      rowsNumber: data.totalItems
    }
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

watch([result, theme], () => onRequest({ pagination: { page: 1 } }))

onMounted(() => {
  store.fetchThemes().catch(() => {})
  onRequest({ pagination: { page: 1 } })
})
</script>

<style scoped>
.history-page {
  max-width: 1100px;
  margin: 0 auto;
}
</style>
