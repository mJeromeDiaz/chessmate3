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
          label="Couronnes en circulation"
          :value="crowns(stats.totals.circulating)"
          :hint="`${stats.totals.holders} joueur(s) en ont`"
          testid="shop-tile-circulating"
        />
        <StatTile
          label="Dépensées"
          :value="crowns(stats.totals.spent)"
          :hint="`${stats.totals.purchases} achat(s) · ${stats.totals.buyers} acheteur(s)`"
          testid="shop-tile-spent"
        />
        <StatTile
          label="Gagnées en session"
          :value="crowns(stats.totals.earned ?? 0)"
          :hint="`${stats.totals.rewarded ?? 0} session(s) récompensée(s)`"
          testid="shop-tile-earned"
        />
        <StatTile
          label="Données"
          :value="crowns(stats.totals.granted)"
          :hint="`${crowns(stats.totals.revoked)} retirées`"
          testid="shop-tile-granted"
        />
        <StatTile
          label="Jours de gel en stock"
          :value="crowns(stats.freezeDays)"
          hint="chez tous les joueurs"
          testid="shop-tile-freezes"
        />
      </div>
      <ColumnChart
        title="Couronnes dépensées par jour"
        :summary="crowns(stats.totals.spent)"
        :columns="daily"
        :series="[{ id: 'value', label: 'Couronnes', ...BRAND_SERIES }]"
        :scale="niceScale(Math.max(0, ...daily.map(c => c.values.value)))"
        :format-value="crowns"
        :format-tick="crowns"
        empty-text="Aucun achat sur la période."
        testid="chart-shop-spent"
      />
      <section class="cm-card q-mt-md">
        <h2 class="cm-card__title q-mb-sm">Ventes par offre</h2>
        <q-markup-table flat dense class="admin__table">
          <thead>
            <tr>
              <th class="text-left">Offre</th>
              <th class="text-right">Achats</th>
              <th class="text-right">Quantité</th>
              <th class="text-right">Couronnes</th>
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
              <td colspan="4" class="cm-muted">Aucune vente sur la période.</td>
            </tr>
          </tbody>
        </q-markup-table>
      </section>
      <section class="cm-card q-mt-md">
        <h2 class="cm-card__title q-mb-sm">Objets détenus</h2>
        <div class="shop-stats__held">
          <span
            v-for="(qty, item) in stats.held"
            :key="item"
            class="admin__status"
            >{{ ITEMS[item]?.label ?? item }} × {{ crowns(qty) }}</span
          >
          <span v-if="Object.keys(stats.held).length === 0" class="cm-muted"
            >Aucun.</span
          >
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

const PERIODS = [
  { value: 7, label: '7 j' },
  { value: 30, label: '30 j' },
  { value: 90, label: '90 j' }
]

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
