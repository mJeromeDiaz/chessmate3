<template>
  <router-link to="/stats" class="stats-link" data-testid="dashboard-stats">
    <span class="stats-link__bars" aria-hidden="true">
      <span v-for="(h, i) in BARS" :key="i" :style="{ height: `${h}%` }" />
    </span>
    <span class="stats-link__label">{{ $t('dashboard.home.statsTile') }}</span>
    <span class="stats-link__go">{{ $t('dashboard.home.statsOpen') }}</span>
  </router-link>
</template>

<script setup>
/**
 * « Mes statistiques détaillées » (design « Dashboard v2 »): a thin row on a phone, a tile beside
 * the level banner from md. The rating curves live there now (docs/DASHBOARD.md).
 */

/** The little chart's bars, in % of its height. */
const BARS = [40, 65, 50, 80, 100]
</script>

<style scoped lang="scss">
.stats-link {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 46px;
  padding: 0 16px;
  border-radius: 18px;
  background: var(--cm-surface);
  box-shadow: var(--cm-card-shadow);
  color: var(--cm-ink);
  font-weight: 800;
  font-size: 14px;
  text-decoration: none;

  @media (min-width: $breakpoint-md-min) {
    flex-direction: column;
    align-items: flex-start;
    justify-content: space-between;
    gap: 12px;
    padding: 20px 22px;
    border-radius: 26px;
  }
}

.stats-link__bars {
  display: flex;
  align-items: flex-end;
  gap: 2px;
  height: 16px;

  > span {
    width: 4px;
    border-radius: 2px;
    background: var(--cm-hero);

    &:last-child {
      background: var(--cm-hero-deep);
    }
  }

  @media (min-width: $breakpoint-md-min) {
    gap: 4px;
    height: 40px;

    > span {
      width: 12px;
      border-radius: 4px;
      opacity: 0.55;

      &:last-child {
        opacity: 1;
      }
    }
  }
}

.stats-link__label {
  flex: 1;

  @media (min-width: $breakpoint-md-min) {
    flex: none;
    font-family: var(--cm-heading);
    font-size: 19px;
    line-height: 1.15;
  }
}

.stats-link__go {
  color: var(--cm-brand);
}
</style>
