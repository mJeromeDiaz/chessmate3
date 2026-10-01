<template>
  <q-page padding>
    <div class="stats-page q-gutter-y-md">
      <div class="row items-center no-wrap">
        <q-btn
          flat
          round
          dense
          icon="arrow_back"
          :to="`/repertoire/${id}`"
          aria-label="Éditeur"
        />
        <div class="text-h5 q-ml-sm ellipsis" data-testid="stats-name">{{
          stats?.name ?? 'Statistiques'
        }}</div>
        <q-space />
        <q-btn
          color="primary"
          no-caps
          icon="timer"
          label="Tester"
          :disable="!stats"
          data-testid="stats-test"
          @click="openTest(null)"
        />
      </div>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <q-skeleton v-if="loading" type="rect" height="300px" />

      <template v-else-if="stats">
        <CardCounts :cards="stats.cards" />
        <div>
          <div class="text-subtitle1 q-mb-xs"
            >Positions dues, 7 prochains jours</div
          >
          <ForecastBars :days="stats.forecast" />
        </div>

        <div class="row q-col-gutter-md" data-testid="stats-tests">
          <div v-for="t in testFigures" :key="t.label" class="col-6 col-sm-3">
            <div class="text-caption text-grey">{{ t.label }}</div>
            <div class="text-h6" :data-testid="t.testid">{{ t.value }}</div>
          </div>
        </div>

        <q-card v-if="stats.fragile.length" flat bordered data-testid="fragile">
          <q-card-section class="row items-center">
            <div class="text-subtitle1">Tronçons fragiles</div>
            <q-space />
            <q-btn
              flat
              no-caps
              color="primary"
              label="Tester les fragiles"
              data-testid="fragile-test-all"
              @click="openTest(stats.fragile.map(s => s.id))"
            />
          </q-card-section>
          <q-list separator>
            <q-item
              v-for="s in stats.fragile"
              :key="s.id"
              data-testid="fragile-row"
            >
              <q-item-section>
                <q-item-label>{{ labelText(s.label) }}</q-item-label>
                <q-item-label caption
                  >{{ formatPercent(s.tests.recentFailureRate) }} d’échecs sur
                  les {{ s.tests.recent }} derniers tests</q-item-label
                >
              </q-item-section>
              <q-item-section side>
                <q-btn
                  flat
                  dense
                  no-caps
                  label="Tester"
                  @click="openTest([s.id])"
                />
              </q-item-section>
            </q-item>
          </q-list>
        </q-card>

        <q-table
          flat
          bordered
          title="Tronçons"
          :rows="stats.segments"
          :columns="columns"
          row-key="id"
          :pagination="{ rowsPerPage: 25 }"
          data-testid="segments-table"
          @row-click="(_event, row) => openHistory(row.id)"
        >
          <template #body-cell-label="cell">
            <q-td :props="cell">
              <div>{{ labelText(cell.row.label) }}</div>
              <div class="text-caption text-grey ellipsis segment-path">{{
                numberedMoves(cell.row.path)
              }}</div>
            </q-td>
          </template>
        </q-table>
      </template>

      <RepertoireTestDialog
        v-if="testScope"
        v-model="testOpen"
        :title="testScope.title"
        :caption="testScope.caption"
        :config="testScope.config"
      />
      <SegmentHistoryDialog
        v-model="historyOpen"
        :repertoire-id="id"
        :segment-id="historySegment"
      />
    </div>
  </q-page>
</template>

<script setup>
/**
 * Statistics of one repertoire (GET /repertoires/{id}/stats, docs/REPERTOIRE.md § 15): cards,
 * forecast, tests (a test: the first presentation of a segment in a run), fragile segments with
 * a "Tester" button, every segment with its history.
 */
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import CardCounts from '@/components/repertoire/CardCounts.vue'
import ForecastBars from '@/components/repertoire/ForecastBars.vue'
import RepertoireTestDialog from '@/components/repertoire/RepertoireTestDialog.vue'
import SegmentHistoryDialog from '@/components/repertoire/SegmentHistoryDialog.vue'
import { repertoireApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate, formatPercent } from '@/utils/format'
import { labelText, numberedMoves, statusText } from '@/utils/repertoireTest'

definePage({ meta: { auth: 'required' } })

const route = useRoute()
const id = String(route.params.id)
const stats = ref(null)
const loading = ref(true)
const error = ref('')
const testOpen = ref(false)
/** @type {import('vue').Ref<{title: string, caption: string, config: Record<string, any>}|null>} */
const testScope = ref(null)
const historyOpen = ref(false)
const historySegment = ref(null)

const columns = [
  { name: 'label', label: 'Tronçon', field: 'id', align: 'left' },
  {
    name: 'due',
    label: 'Dues',
    field: row => row.cards.due,
    sortable: true
  },
  {
    name: 'tests',
    label: 'Tests',
    field: row => row.tests.total,
    sortable: true
  },
  {
    name: 'rate',
    label: 'Réussite',
    field: row => row.tests.successRate,
    format: formatPercent,
    sortable: true
  },
  {
    name: 'last',
    label: 'Dernier test',
    field: row => row.tests.lastAt,
    format: (value, row) =>
      value
        ? `${formatDate(value)} · ${statusText(row.tests.lastStatus)}`
        : '—',
    sortable: true
  }
]

const testFigures = computed(() => {
  const t = stats.value?.tests
  if (!t) return []
  return [
    { label: 'Tests', value: t.total, testid: 'tests-total' },
    {
      label: 'Réussite',
      value: formatPercent(t.successRate),
      testid: 'tests-rate'
    },
    {
      label: '7 / 30 jours',
      value: `${t.last7} / ${t.last30}`,
      testid: 'tests-recent'
    },
    { label: 'Dernier test', value: formatDate(t.lastAt), testid: 'tests-last' }
  ]
})

/** @param {string[]|null} segmentIds null: the whole repertoire */
function openTest(segmentIds) {
  testScope.value = segmentIds
    ? {
        title:
          segmentIds.length > 1
            ? 'Tester les tronçons fragiles'
            : 'Tester ce tronçon',
        caption: stats.value?.name ?? '',
        config: { repertoireIds: [id], segmentIds }
      }
    : {
        title: 'Tester le répertoire',
        caption: stats.value?.name ?? '',
        config: { repertoireIds: [id] }
      }
  testOpen.value = true
}

/** @param {string} segmentId */
function openHistory(segmentId) {
  historySegment.value = segmentId
  historyOpen.value = true
}

onMounted(async () => {
  try {
    stats.value = await repertoireApi.stats(id)
  } catch (e) {
    error.value = apiErrorMessage(e, { 404: 'Répertoire introuvable.' })
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.stats-page {
  max-width: 900px;
  margin: 0 auto;
}
.segment-path {
  max-width: 420px;
}
</style>
