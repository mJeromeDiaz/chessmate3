<template>
  <section v-if="weak" class="cm-card weak-tip" data-testid="weak-theme-tip">
    <div class="weak-tip__kicker">À TRAVAILLER · {{ TIP_DAYS }} JOURS</div>
    <div class="weak-tip__line">
      <span class="weak-tip__name">{{ puzzles.themeLabel(weak.key) }}</span>
      <strong>{{ formatPercent(weak.successRate) }} de réussite</strong>
    </div>
    <div class="weak-tip__actions">
      <q-btn
        unelevated
        no-caps
        color="dark"
        label="S’entraîner"
        data-testid="weak-theme-train"
        @click="train"
      />
      <router-link to="/stats" class="weak-tip__link"
        >Mes statistiques →</router-link
      >
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { TIP_DAYS, useDashboardStore } from '@/stores/dashboard'
import { usePuzzleStore } from '@/stores/puzzle'
import { weakestTheme } from '@/utils/dashboard/stats'
import { formatPercent } from '@/utils/format'

/**
 * The home page's tip: the weakest puzzle theme of the last 30 days, and a button to train it
 * (rated puzzles filtered on it). Hidden when there is none, or when it cannot be loaded.
 */
const store = useDashboardStore()
const puzzles = usePuzzleStore()
const router = useRouter()

const weak = computed(() => weakestTheme(store.tipThemes))

function train() {
  if (!weak.value) return
  puzzles.setThemes([weak.value.key])
  router.push('/puzzle')
}

onMounted(() => {
  store.loadTip()
  puzzles.fetchThemes().catch(() => null)
})
</script>

<style scoped lang="scss">
.weak-tip__kicker {
  font-size: 11px;
  font-weight: 700;
  color: var(--cm-muted);
  letter-spacing: 0.04em;
}

.weak-tip__line {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
  margin-top: 4px;

  strong {
    font-size: 13px;
    font-variant-numeric: tabular-nums;
  }
}

.weak-tip__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 19px;
}

.weak-tip__actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 12px;
}

.weak-tip__link {
  color: var(--cm-brand);
  font-weight: 700;
  font-size: 13px;
  text-decoration: none;
}
</style>
