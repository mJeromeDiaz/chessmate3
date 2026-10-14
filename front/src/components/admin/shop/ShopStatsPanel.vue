<template>
  <div class="shop-stats" data-testid="shop-stats">
    <div class="shop-stats__period">
      <q-btn-toggle
        v-model="days"
        :options="PERIODS"
        no-caps
        unelevated
        toggle-color="primary"
        data-testid="shop-stats-period"
        @update:model-value="load"
      />
    </div>
    <q-banner v-if="error" class="cm-banner--danger q-mb-md" rounded>{{
      error
    }}</q-banner>
    <template v-if="stats">
      <div class="shop-stats__tiles">
        <StatTile
          :label="$t('admin.shopStatsPanel.couronnesEnCirculation')"
          :value="crowns(stats.totals.circulating)"
          :hint="
            $t('admin.shopStatsPanel.holders', { n: stats.totals.holders })
          "
          testid="shop-tile-circulating"
        />
        <StatTile
          :label="$t('admin.shopStatsPanel.depensees')"
          :value="crowns(stats.totals.spent)"
          :hint="
            $t('admin.shopStatsPanel.purchases', {
              n: stats.totals.purchases,
              buyers: stats.totals.buyers
            })
          "
          testid="shop-tile-spent"
        />
        <StatTile
          :label="$t('admin.shopStatsPanel.gagneesEnSession')"
          :value="crowns(stats.totals.earned ?? 0)"
          :hint="
            $t('admin.shopStatsPanel.rewarded', {
              n: stats.totals.rewarded ?? 0
            })
          "
          testid="shop-tile-earned"
        />
        <StatTile
          :label="$t('admin.shopStatsPanel.donnees')"
          :value="crowns(stats.totals.granted)"
          :hint="
            $t('admin.shopStatsPanel.revoked', {
              n: crowns(stats.totals.revoked)
            })
          "
          testid="shop-tile-granted"
        />
        <StatTile
          :label="$t('admin.shopStatsPanel.joursDeGelEn')"
          :value="crowns(stats.freezeDays)"
          :hint="$t('admin.shopStatsPanel.chezTousLesJoueurs')"
          testid="shop-tile-freezes"
        />
      </div>
      <ColumnChart
        :title="$t('admin.shopStatsPanel.couronnesDepenseesParJour')"
        :summary="crowns(stats.totals.spent)"
        :columns="daily"
        :series="[
          {
            id: 'value',
            label: $t('admin.shopStatsPanel.crowns'),
            ...BRAND_SERIES
          }
        ]"
        :scale="niceScale(Math.max(0, ...daily.map(c => c.values.value)))"
        :format-value="crowns"
        :format-tick="crowns"
        :empty-text="$t('admin.shopStatsPanel.aucunAchatSurLa')"
        testid="chart-shop-spent"
      />
      <section class="cm-card q-mt-md">
        <h2 class="cm-card__title q-mb-sm">{{
          $t('admin.shopStatsPanel.ventesParOffre')
        }}</h2>
        <q-markup-table flat dense class="admin__table">
          <thead>
            <tr>
              <th class="text-left">{{ $t('admin.shopStatsPanel.offre') }}</th>
              <th class="text-right">{{
                $t('admin.shopStatsPanel.achats')
              }}</th>
              <th class="text-right">{{
                $t('admin.shopStatsPanel.quantite')
              }}</th>
              <th class="text-right">{{
                $t('admin.shopStatsPanel.couronnes')
              }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="o in stats.offers" :key="o.offerName">
              <td>{{ o.offerName }}</td>
              <td class="text-right">{{ o.purchases }}</td>
              <td class="text-right">{{ o.quantity }}</td>
              <td class="text-right">{{ crowns(o.crowns) }}</td>
            </tr>
            <tr v-if="stats.offers.length === 0">
              <td colspan="4" class="cm-muted">{{
                $t('admin.shopStatsPanel.aucuneVenteSurLa')
              }}</td>
            </tr>
          </tbody>
        </q-markup-table>
      </section>
      <section class="cm-card q-mt-md">
        <h2 class="cm-card__title q-mb-sm">{{
          $t('admin.shopStatsPanel.objetsDetenus')
        }}</h2>
        <div class="shop-stats__held">
          <span
            v-for="(qty, item) in stats.held"
            :key="item"
            class="admin__status"
            >{{
              $t('admin.shopPlayers.item', {
                item: ITEMS[item]?.label ?? item,
                n: crowns(qty)
              })
            }}</span
          >
          <span v-if="Object.keys(stats.held).length === 0" class="cm-muted">{{
            $t('admin.shopStatsPanel.aucun')
          }}</span>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
/**
 * The store's figures for the admin (docs/SHOP.md), over 7, 30 or 90 days.
 */
import { computed, onMounted, ref } from 'vue'
import ColumnChart from '@/components/admin/ColumnChart.vue'
import StatTile from '@/components/admin/StatTile.vue'
import { adminApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { BRAND_SERIES, niceScale } from '@/utils/admin/charts'
import { dayLabel } from '@/utils/dashboard/stats'
import { ITEMS, crowns } from '@/utils/shop'
import { t } from '@/i18n'

const PERIODS = [7, 30, 90].map(value => ({
  value,
  get label() {
    return t('admin.shopStatsPanel.days', { n: value })
  }
}))

const days = ref(30)
/** @type {import('vue').Ref<any|null>} */
const stats = ref(null)
const error = ref('')

const daily = computed(() =>
  (stats.value?.daily ?? []).map(d => ({
    key: d.date,
    label: dayLabel(d.date),
    values: { value: d.crowns }
  }))
)

async function load() {
  error.value = ''
  try {
    stats.value = await adminApi.shopStats(days.value)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}

onMounted(load)
</script>

<style scoped lang="scss">
.shop-stats__period {
  margin-bottom: 12px;
}

.shop-stats__tiles {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}

.shop-stats__held {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
</style>
