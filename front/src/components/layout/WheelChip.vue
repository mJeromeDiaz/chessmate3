<template>
  <button
    v-if="count > 0"
    type="button"
    class="wheel-chip"
    :aria-label="$t('layout.header.wheels', { n: count }, count)"
    data-testid="header-wheel"
    @click="shop.openWheel()"
  >
    <WheelGlyph :size="22" />
    <span class="wheel-chip__count" data-testid="header-wheel-count">{{
      count
    }}</span>
    <q-tooltip>{{ $t('layout.header.wheels', { n: count }, count) }}</q-tooltip>
  </button>
</template>

<script setup>
/**
 * The gift wheels to spin, in the header (docs/SHOP.md, § 4 ter): today's still to spin and the
 * tickets held, their number pulsing; a tap opens the wheel. Hidden when there is none.
 */
import { computed, onMounted } from 'vue'
import WheelGlyph from '@/components/shop/WheelGlyph.vue'
import { useShopStore } from '@/stores/shop'

const shop = useShopStore()
const count = computed(() => shop.wheelsToSpin)

onMounted(() => shop.loadOnce().catch(() => {}))
</script>

<style scoped lang="scss">
.wheel-chip {
  position: relative;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 32px;
  padding: 0;
  border: 0;
  border-radius: 12px;
  background: #c6f432;
  cursor: pointer;
}

.wheel-chip__count {
  position: absolute;
  top: -6px;
  right: -6px;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: 9px;
  background: #ff3d5a;
  color: #fff;
  font-size: 11px;
  font-weight: 800;
  line-height: 18px;
  text-align: center;
  font-variant-numeric: tabular-nums;
  box-shadow: 0 0 0 2px var(--cm-surface, #fff);
  animation: wheel-chip-pulse 1.4s ease-in-out infinite;
}

@keyframes wheel-chip-pulse {
  0%,
  100% {
    transform: scale(1);
    opacity: 1;
  }
  50% {
    transform: scale(1.18);
    opacity: 0.55;
  }
}

@media (prefers-reduced-motion: reduce) {
  .wheel-chip__count {
    animation: none;
  }
}
</style>
