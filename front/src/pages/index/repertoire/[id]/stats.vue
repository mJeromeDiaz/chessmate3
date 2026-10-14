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
          :aria-label="$t('repertoire.stats.editor')"
        />
        <div class="text-h5 q-ml-sm ellipsis" data-testid="stats-name">{{
          stats?.name ?? $t('repertoire.stats.title')
        }}</div>
        <q-space />
        <q-btn
          color="primary"
          no-caps
          icon="timer"
          :label="$t('repertoire.stats.test')"
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
          <div class="text-subtitle1 q-mb-xs">{{
            $t('repertoire.overview.forecast')
          }}</div>
          <ForecastBars :days="stats.forecast" />
        </div>

        <div class="row q-col-gutter-md" data-testid="stats-tests">
          <div
            v-for="figure in testFigures"
            :key="figure.label"
            class="col-6 col-sm-3"
          >
            <div class="text-caption text-grey">{{ figure.label }}</div>
            <div class="text-h6" :data-testid="figure.testid">{{
              figure.value
            }}</div>
          </div>
        </div>

        <q-card v-if="stats.fragile.length" flat bordered data-testid="fragile">
          <q-card-section class="row items-center">
            <div class="text-subtitle1">{{
              $t('repertoire.stats.fragile')
            }}</div>
            <q-space />
            <q-btn
              flat
              no-caps
              color="primary"
              :label="$t('repertoire.stats.testFragile')"
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
                <q-item-label caption>{{
                  $t('repertoire.stats.fragileRow', {
                    rate: formatPercent(s.tests.recentFailureRate),
                    n: s.tests.recent
                  })
                }}</q-item-label>
              </q-item-section>
              <q-item-section side>
                <q-btn
                  flat
                  dense
                  no-caps
                  :label="$t('repertoire.stats.test')"
                  @click="openTest([s.id])"
                />
              </q-item-section>
            </q-item>
          </q-list>
        </q-card>

        <q-table
          flat
          bordered
          :title="$t('repertoire.stats.segments')"
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
import { t } from '@/i18n'

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

const columns = computed(() => [
  {
    name: 'label',
    label: t('repertoire.stats.columns.label'),
    field: 'id',
    align: 'left'
  },
  {
    name: 'due',
    label: t('repertoire.stats.columns.due'),
    field: row => row.cards.due,
    sortable: true
  },
  {
    name: 'tests',
    label: t('repertoire.stats.columns.tests'),
    field: row => row.tests.total,
    sortable: true
  },
  {
    name: 'rate',
    label: t('repertoire.stats.columns.rate'),
    field: row => row.tests.successRate,
    format: formatPercent,
    sortable: true
  },
  {
    name: 'last',
    label: t('repertoire.stats.columns.last'),
    field: row => row.tests.lastAt,
    format: (value, row) =>
      value
        ? t('repertoire.stats.last', {
            date: formatDate(value),
            status: statusText(row.tests.lastStatus)
          })
        : '—',
    sortable: true
  }
])

const testFigures = computed(() => {
  const tests = stats.value?.tests
  if (!tests) return []
  return [
    {
      label: t('repertoire.stats.figures.tests'),
      value: tests.total,
      testid: 'tests-total'
    },
    {
      label: t('repertoire.stats.figures.rate'),
      value: formatPercent(tests.successRate),
      testid: 'tests-rate'
    },
    {
      label: t('repertoire.stats.figures.recent'),
      value: `${tests.last7} / ${tests.last30}`,
      testid: 'tests-recent'
    },
    {
      label: t('repertoire.stats.figures.last'),
      value: formatDate(tests.lastAt),
      testid: 'tests-last'
    }
  ]
})

/** @param {string[]|null} segmentIds null: the whole repertoire */
function openTest(segmentIds) {
  testScope.value = segmentIds
    ? {
        title: t(
          segmentIds.length > 1
            ? 'repertoire.stats.testFragileTitle'
            : 'repertoire.stats.testOneTitle'
        ),
        caption: stats.value?.name ?? '',
        config: { repertoireIds: [id], segmentIds }
      }
    : {
        title: t('repertoire.list.testOne'),
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
    error.value = apiErrorMessage(e, { 404: t('repertoire.stats.notFound') })
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
