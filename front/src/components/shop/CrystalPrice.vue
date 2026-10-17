<template>
  <span class="crown-price" :class="`crown-price--${variant}`">
    <CrownCoin :size="coin" />{{ crowns(amount) }}
  </span>
</template>

<script setup>
/**
 * A price in crowns: the coin and the amount, plain, on a pale chip or on a gold one.
 */
import CrownCoin from '@/components/shop/CrownCoin.vue'
import { crowns } from '@/utils/shop'

defineProps({
  amount: { type: Number, required: true },
  /** @type {import('vue').PropType<'plain'|'chip'|'gold'>} */
  variant: { type: String, default: 'plain' },
  coin: { type: Number, default: 18 }
})
</script>

<style scoped lang="scss">
.crown-price {
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-weight: 800;
  font-size: 15px;
  font-variant-numeric: tabular-nums;
  color: var(--cm-ink);

  &--chip {
    height: 34px;
    padding: 0 10px 0 4px;
    border-radius: 12px;
    background: #fff5d1;
    color: #1b1530;
    font-size: 14px;
  }

  &--gold {
    height: 34px;
    padding: 0 12px 0 5px;
    border-radius: 12px;
    background: #ffd43b;
    color: #1b1530;
  }
}
</style>
