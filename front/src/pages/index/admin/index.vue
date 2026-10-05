<template>
  <AdminPage testid="admin-overview">
    <template #actions>
      <PeriodPicker
        :model-value="store.period"
        @update:model-value="store.loadStats"
      />
    </template>

    <q-banner
      v-if="store.statsError && !stats"
      class="cm-banner--danger"
      rounded
    >
      {{ store.statsError }}
      <template #action>
        <q-btn flat no-caps label="Réessayer" @click="store.loadStats()" />
      </template>
    </q-banner>

    <q-skeleton v-else-if="!stats" height="420px" class="admin__skeleton" />

    <template v-else>
      <p class="admin__period cm-muted">
        Du {{ dayLabel(stats.from) }} au {{ dayLabel(stats.today) }} ({{
          stats.timezone
        }})
      </p>

      <div class="admin__tiles">
        <StatTile
          label="Comptes"
          :value="formatCount(stats.signups.accounts)"
          :hint="accountsHint"
          testid="tile-accounts"
        />
        <StatTile
          label="Inscriptions sur la période"
          :value="formatCount(stats.signups.total)"
          testid="tile-signups"
        />
        <StatTile
          label="Joueurs actifs"
          :value="formatCount(stats.activity.active)"
          :hint="`${plural(stats.activity.inactive, 'inactif', 'inactifs')} · un exercice en 7 jours`"
          testid="tile-active"
        />
        <StatTile
          label="Temps moyen"
          :value="formatHours(stats.activity.averageWeeklyMs)"
          hint="par joueur actif et par semaine"
          testid="tile-time"
        />
        <StatTile
          label="Elo médian (Lichess)"
          :value="formatCount(stats.ratings.median)"
          :hint="`${plural(stats.ratings.rated, 'classé', 'classés')} · ${stats.ratings.notLinked} sans Lichess`"
          testid="tile-elo"
        />
      </div>

      <div class="admin__grid">
        <ColumnChart
          class="admin__wide"
          title="Inscriptions par jour"
          :summary="formatCount(stats.signups.total)"
          :columns="signups"
          :series="SIGNUP_METHODS"
          :scale="niceScale(maxTotal(signups, SIGNUP_METHODS))"
          :format-value="formatCount"
          :format-tick="formatCount"
          empty-text="Aucune inscription sur la période."
          testid="chart-signups"
        />
        <ColumnChart
          class="admin__wide"
          title="Joueurs actifs par jour"
          :columns="active"
          :series="[{ id: 'value', label: 'Joueurs actifs', ...BRAND_SERIES }]"
          :scale="niceScale(maxTotal(active, VALUE))"
          :format-value="formatCount"
          :format-tick="formatCount"
          empty-text="Aucun exercice sur la période."
          testid="chart-active"
        />
        <ColumnChart
          title="Temps moyen par semaine"
          :summary="formatHours(stats.activity.averageWeeklyMs)"
          :columns="weekly"
          :series="[
            { id: 'value', label: 'Par joueur actif', ...BRAND_SERIES }
          ]"
          :scale="durationScale(maxTotal(weekly, VALUE))"
          :format-value="formatHours"
          :format-tick="formatHours"
          key-header="Semaine"
          empty-text="Aucun exercice sur la période."
          testid="chart-weekly"
        />
        <ColumnChart
          title="Elo Lichess des joueurs"
          :summary="`médiane ${formatCount(stats.ratings.median)}`"
          :columns="ratings"
          :series="[{ id: 'value', label: 'Joueurs', ...BRAND_SERIES }]"
          :scale="niceScale(maxTotal(ratings, VALUE))"
          :format-value="formatCount"
          :format-tick="formatCount"
          key-header="Tranche"
          empty-text="Aucun joueur avec une cote Lichess (rapide, blitz ou classique, non provisoire)."
          testid="chart-ratings"
        />
        <FunnelCard class="admin__wide" :invitations="stats.invitations" />
      </div>
    </template>
  </AdminPage>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useAdminStore } from '@/stores/admin'
import PeriodPicker from '@/components/dashboard/PeriodPicker.vue'
import AdminPage from '@/components/admin/AdminPage.vue'
import StatTile from '@/components/admin/StatTile.vue'
import ColumnChart from '@/components/admin/ColumnChart.vue'
import FunnelCard from '@/components/admin/FunnelCard.vue'
import { dayLabel, formatHours } from '@/utils/dashboard/stats'
import {
  BRAND_SERIES,
  SIGNUP_METHODS,
  activeColumns,
  durationScale,
  formatCount,
  niceScale,
  plural,
  ratingColumns,
  signupColumns,
  weeklyTimeColumns
} from '@/utils/admin/charts'

/** The admin dashboard (docs/EARLY_ACCESS.md): key figures and charts of the chosen period. */
definePage({ meta: { auth: 'admin' } })

const store = useAdminStore()
const stats = computed(() => store.stats)

const VALUE = [{ id: 'value' }]
const accountsHint = computed(() => {
  const { unverified, suspended } = stats.value.signups
  const parts = [plural(unverified, 'email non vérifié', 'emails non vérifiés')]
  if (suspended > 0) parts.push(plural(suspended, 'suspendu', 'suspendus'))
  return parts.join(' · ')
})
const signups = computed(() => signupColumns(stats.value.signups.days))
const active = computed(() => activeColumns(stats.value.activity.days))
const weekly = computed(() => weeklyTimeColumns(stats.value.activity.weeks))
const ratings = computed(() =>
  ratingColumns(stats.value.ratings.bands, stats.value.ratings.bandWidth)
)

/**
 * The tallest column's total.
 *
 * @param {import('@/utils/admin/charts').Column[]} columns
 * @param {{id: string}[]} series
 * @returns {number}
 */
function maxTotal(columns, series) {
  return Math.max(
    0,
    ...columns.map(c => series.reduce((s, x) => s + (c.values[x.id] ?? 0), 0))
  )
}

onMounted(() => store.loadStats())
</script>

<style scoped lang="scss">
.admin__period {
  margin: 0 0 12px;
  font-size: 13px;
}

.admin__tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

.admin__grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 12px;

  @media (min-width: $breakpoint-md-min) {
    grid-template-columns: 1fr 1fr;

    .admin__wide {
      grid-column: 1 / -1;
    }
  }
}

.admin__skeleton {
  border-radius: 22px;
}
</style>
