<template>
  <q-page padding>
    <div class="stats-page q-gutter-y-md">
      <div class="row items-center">
        <q-btn
          flat
          round
          dense
          icon="arrow_back"
          to="/repertoire"
          aria-label="Mes répertoires"
        />
        <div class="text-h5 q-ml-sm">Statistiques des répertoires</div>
      </div>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>
      <q-skeleton v-if="loading" type="rect" height="200px" />

      <template v-else-if="overview">
        <CardCounts :cards="overview.cards" />
        <div>
          <div class="text-subtitle1 q-mb-xs"
            >Positions dues, 7 prochains jours</div
          >
          <ForecastBars :days="overview.forecast" />
        </div>
        <q-list bordered separator data-testid="overview-repertoires">
          <q-item
            v-for="r in overview.repertoires"
            :key="r.id"
            clickable
            :to="`/repertoire/${r.id}/stats`"
          >
            <q-item-section>
              <q-item-label>{{ r.name }}</q-item-label>
              <q-item-label caption>
                {{ r.cards.due }} dues · {{ r.cards.new }} nouvelles ·
                {{ r.tests }} tests · réussite 30 j :
                {{ formatPercent(r.successRate30) }} · dernier test :
                {{ formatDate(r.lastTestedAt) }}
              </q-item-label>
            </q-item-section>
            <q-item-section side
              ><q-icon name="chevron_right"
            /></q-item-section>
          </q-item>
        </q-list>
      </template>
    </div>
  </q-page>
</template>

<script setup>
/**
 * All repertoires at a glance (GET /repertoires/stats): cards to review, tests, forecast.
 */
import { onMounted, ref } from 'vue'
import CardCounts from '@/components/repertoire/CardCounts.vue'
import ForecastBars from '@/components/repertoire/ForecastBars.vue'
import { repertoireApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { formatDate, formatPercent } from '@/utils/format'

definePage({ meta: { auth: 'required' } })

const overview = ref(null)
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  try {
    overview.value = await repertoireApi.overview()
  } catch (e) {
    error.value = apiErrorMessage(e)
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
</style>
