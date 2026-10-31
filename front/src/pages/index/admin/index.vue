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
        <q-btn
          flat
          no-caps
          :label="$t('admin.overview.reessayer')"
          @click="store.loadStats()"
        />
      </template>
    </q-banner>

    <q-skeleton v-else-if="!stats" height="420px" class="admin__skeleton" />

    <template v-else>
      <p class="admin__period cm-muted">
        {{
          $t('admin.overview.period', {
            from: dayLabel(stats.from),
            to: dayLabel(stats.today),
            tz: stats.timezone
          })
        }}
      </p>

      <div class="admin__tiles">
        <StatTile
          :label="$t('admin.overview.comptes')"
          :value="formatCount(stats.signups.accounts)"
          :hint="accountsHint"
          testid="tile-accounts"
        />
        <StatTile
          :label="$t('admin.overview.inscriptionsSurLaPeriode')"
          :value="formatCount(stats.signups.total)"
          testid="tile-signups"
        />
        <StatTile
          :label="$t('admin.overview.joueursActifs')"
          :value="formatCount(stats.activity.active)"
          :hint="
            $t(
              'admin.overview.inactive',
              { n: formatCount(stats.activity.inactive) },
              stats.activity.inactive
            )
          "
          testid="tile-active"
        />
        <StatTile
          :label="$t('admin.overview.tempsMoyen')"
          :value="formatHours(stats.activity.averageWeeklyMs)"
          :hint="$t('admin.overview.parJoueurActifEt')"
          testid="tile-time"
        />
        <StatTile
          :label="$t('admin.overview.eloMedianLichess')"
          :value="formatCount(stats.ratings.median)"
          :hint="
            $t(
              'admin.overview.rated',
              {
                n: formatCount(stats.ratings.rated),
                notLinked: stats.ratings.notLinked
              },
              stats.ratings.rated
            )
          "
          testid="tile-elo"
        />
      </div>

      <div class="admin__grid">
        <ColumnChart
          class="admin__wide"
          :title="$t('admin.overview.inscriptionsParJour')"
          :summary="formatCount(stats.signups.total)"
          :columns="signups"
          :series="SIGNUP_METHODS"
          :scale="niceScale(maxTotal(signups, SIGNUP_METHODS))"
          :format-value="formatCount"
          :format-tick="formatCount"
          :empty-text="$t('admin.overview.aucuneInscriptionSurLa')"
          testid="chart-signups"
        />
        <ColumnChart
          class="admin__wide"
          :title="$t('admin.overview.joueursActifsParJour')"
          :columns="active"
          :series="[
            {
              id: 'value',
              label: $t('admin.overview.seriesActive'),
              ...BRAND_SERIES
            }
          ]"
          :scale="niceScale(maxTotal(active, VALUE))"
          :format-value="formatCount"
          :format-tick="formatCount"
          :empty-text="$t('admin.overview.aucunExerciceSurLa')"
          testid="chart-active"
        />
        <ColumnChart
          :title="$t('admin.overview.tempsMoyenParSemaine')"
          :summary="formatHours(stats.activity.averageWeeklyMs)"
          :columns="weekly"
          :series="[
            {
              id: 'value',
              label: $t('admin.overview.seriesPerPlayer'),
              ...BRAND_SERIES
            }
          ]"
          :scale="durationScale(maxTotal(weekly, VALUE))"
          :format-value="formatHours"
          :format-tick="formatHours"
          :key-header="$t('admin.overview.semaine')"
          :empty-text="$t('admin.overview.aucunExerciceSurLa')"
          testid="chart-weekly"
        />
        <ColumnChart
          :title="$t('admin.overview.eloLichessDesJoueurs')"
          :summary="
            $t('admin.overview.median', {
              n: formatCount(stats.ratings.median)
            })
          "
          :columns="ratings"
          :series="[
            {
              id: 'value',
              label: $t('admin.overview.seriesPlayers'),
              ...BRAND_SERIES
            }
          ]"
          :scale="niceScale(maxTotal(ratings, VALUE))"
          :format-value="formatCount"
          :format-tick="formatCount"
          :key-header="$t('admin.overview.tranche')"
          :empty-text="$t('admin.overview.aucunJoueurAvecUne')"
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
  ratingColumns,
  signupColumns,
  weeklyTimeColumns
} from '@/utils/admin/charts'
import { t } from '@/i18n'

/** The admin dashboard (docs/EARLY_ACCESS.md): key figures and charts of the chosen period. */
definePage({
  meta: {
    auth: 'admin',
    header: {
      title: 'layout.header.titles.admin.index',
      subtitle: 'layout.header.kickers.admin'
    }
  }
})

const store = useAdminStore()
const stats = computed(() => store.stats)

const VALUE = [{ id: 'value' }]
const accountsHint = computed(() => {
  const { unverified, suspended, guests = 0 } = stats.value.signups
  const parts = [
    t('admin.overview.unverified', { n: formatCount(unverified) }, unverified)
  ]
  if (suspended > 0)
    parts.push(
      t('admin.overview.suspended', { n: formatCount(suspended) }, suspended)
    )
  // Guests apart (docs/GUEST.md): never among the accounts.
  if (guests > 0)
    parts.push(t('admin.overview.guests', { n: formatCount(guests) }, guests))
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
