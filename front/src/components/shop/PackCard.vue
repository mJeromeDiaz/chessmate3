<template>
  <button
    type="button"
    class="pack-card shop-tile"
    :data-testid="`shop-offer-${offer.slug}`"
    @click="emit('open', offer)"
  >
    <span class="pack-card__head">
      <span
        class="pack-card__icon"
        :style="{ background: look.tile, color: look.tileInk }"
        aria-hidden="true"
        >{{ look.glyph }}</span
      >
      <span class="pack-card__name">{{ offer.name }}</span>
      <span v-if="saving" class="pack-card__save">{{ saving }}</span>
    </span>
    <span class="pack-card__parts">
      <span v-for="part in parts" :key="part.item" class="pack-card__part"
        ><span
          :style="{ color: $q.dark.isActive ? part.tile : part.color }"
          aria-hidden="true"
          >{{ part.glyph }}</span
        >{{ part.label }}</span
      >
    </span>
    <span class="pack-card__price">
      <span v-if="offer.wasPrice" class="pack-card__was">{{
        crowns(offer.wasPrice)
      }}</span>
      <CrownPrice :amount="offer.price" variant="gold" :coin="24" />
    </span>
  </button>
</template>

<script setup>
/**
 * A pack (design "Boutique", dashboard tiles): its first item's icon, name, saving, contents as
 * chips, struck and gold prices.
 */
import { computed } from 'vue'
import { useQuasar } from 'quasar'
import CrownPrice from '@/components/shop/CrownPrice.vue'
import { crowns, lookOf, partsOf, savingOf } from '@/utils/shop'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/shop').ShopOffer>} */
  offer: { type: Object, required: true }
})

const emit = defineEmits({
  /** @param {import('@/utils/shop').ShopOffer} offer */
  open: offer => !!offer
})

const $q = useQuasar()
const look = computed(() => lookOf(props.offer))
const parts = computed(() => partsOf(props.offer))
const saving = computed(() => savingOf(props.offer))
</script>

<style scoped lang="scss">
.pack-card {
  border: none;
  cursor: pointer;
  text-align: left;
  font-family: inherit;
  color: var(--cm-ink);
  border-radius: 18px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.pack-card__head {
  display: flex;
  align-items: center;
  gap: 10px;
}

.pack-card__icon {
  flex: none;
  width: 40px;
  height: 40px;
  border-radius: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
}

.pack-card__name {
  flex: 1;
  min-width: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
}

.pack-card__save {
  flex: none;
  height: 22px;
  padding: 0 8px;
  border-radius: 999px;
  background: #c6f432;
  color: #1b1530;
  font-size: 11px;
  font-weight: 800;
  display: flex;
  align-items: center;
}

.pack-card__parts {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.pack-card__part {
  height: 28px;
  padding: 0 10px;
  border-radius: 999px;
  background: var(--cm-surface);
  box-shadow: 0 0 0 1px var(--cm-line);
  font-size: 12.5px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 6px;
}

.pack-card__price {
  display: flex;
  align-items: center;
  gap: 8px;
}

.pack-card__was {
  font-size: 13px;
  color: var(--cm-muted);
  text-decoration: line-through;
}
</style>
