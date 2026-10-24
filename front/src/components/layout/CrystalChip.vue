<template>
  <router-link
    v-if="balance !== null"
    to="/boutique"
    class="crystal-chip"
    :aria-label="$t('layout.header.crystals', { n: crowns(balance) })"
    data-testid="crystals"
  >
    <CrystalGem :size="22" />
    <span data-testid="crystals-count">{{ crowns(balance) }}</span>
    <q-tooltip>{{
      $t('layout.header.crystals', { n: crowns(balance) })
    }}</q-tooltip>
  </router-link>
</template>

<script setup>
/** The player's crystals in the header (docs/SHOP.md), a shortcut to the shop. Loaded once. */
import { computed, onMounted } from 'vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import { useShopStore } from '@/stores/shop'
import { crowns } from '@/utils/shop'

const shop = useShopStore()

/** @type {import('vue').ComputedRef<number|null>} */
const balance = computed(() => shop.state?.balance ?? null)

onMounted(() => shop.loadOnce().catch(() => {}))
</script>

<style scoped lang="scss">
.crystal-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  height: 32px;
  padding: 0 10px;
  border-radius: 12px;
  background: var(--cm-brand-soft);
  color: #5a3be0;
  font-weight: 800;
  font-size: 14px;
  text-decoration: none;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.body--dark .crystal-chip {
  color: #c9b8ff;
}
</style>
