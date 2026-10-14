<template>
  <button
    type="button"
    class="offer-tile shop-tile"
    :class="`offer-tile--${layout}`"
    :data-testid="`shop-offer-${offer.slug}`"
    @click="emit('open', offer)"
  >
    <span
      class="offer-tile__icon"
      :style="{ background: look.tile, color: look.tileInk }"
      aria-hidden="true"
      >{{ look.glyph }}<span v-if="layout === 'card'" class="offer-tile__shine"
    /></span>
    <template v-if="layout === 'card'">
      <span v-if="saving" class="offer-tile__save">{{ saving }}</span>
      <span class="offer-tile__body">
        <span class="offer-tile__big">{{ offer.name }}</span>
        <span class="offer-tile__sub">{{ offer.subtitle }}</span>
      </span>
      <span class="offer-tile__foot">
        <CrownPrice :amount="offer.price" />
        <span
          v-if="stock"
          class="offer-tile__stock"
          :style="{ color: look.accent }"
          data-testid="shop-stock"
          >×{{ stock }}</span
        >
      </span>
    </template>
    <template v-else>
      <span class="offer-tile__body">
        <span class="offer-tile__name"
          >{{ offer.name
          }}<span
            v-if="stock"
            class="offer-tile__stock"
            :style="{ color: look.accent }"
            data-testid="shop-stock"
            >×{{ stock }}</span
          ></span
        >
        <span class="offer-tile__desc">{{ offer.description }}</span>
      </span>
      <CrownPrice :amount="offer.price" variant="chip" />
    </template>
  </button>
</template>

<script setup>
/**
 * An offer in the store (design "Boutique", dashboard tiles: `.shop-tile` of the page): a card (the freezes, by two) or a row (Puzzle Streak,
 * module aids), its price and how many the player holds.
 */
import { computed } from 'vue'
import CrownPrice from '@/components/shop/CrownPrice.vue'
import { lookOf, savingOf } from '@/utils/shop'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/shop').ShopOffer>} */
  offer: { type: Object, required: true },
  stock: { type: Number, default: 0 },
  /** @type {import('vue').PropType<'card'|'row'>} */
  layout: { type: String, default: 'row' }
})

const emit = defineEmits({
  /** @param {import('@/utils/shop').ShopOffer} offer */
  open: offer => !!offer
})

const look = computed(() => lookOf(props.offer))
const saving = computed(() => savingOf(props.offer))
</script>

<style scoped lang="scss">
.offer-tile {
  position: relative;
  overflow: hidden;
  border: none;
  cursor: pointer;
  text-align: left;
  font-family: inherit;
  color: var(--cm-ink);
  border-radius: 18px;

  &--card {
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  &--row {
    padding: 12px 14px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
}

.offer-tile__icon {
  position: relative;
  overflow: hidden;
  flex: none;
  width: 48px;
  height: 48px;
  border-radius: 15px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 24px;
}

.offer-tile__shine {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 30%;
  background: rgba(255, 255, 255, 0.75);
  animation: offer-shine 3s ease-in-out infinite;
}

.offer-tile__save {
  position: absolute;
  top: 12px;
  right: 12px;
  height: 20px;
  padding: 0 7px;
  border-radius: 999px;
  background: #f0fbcf;
  color: #5a7a00;
  font-size: 11px;
  font-weight: 800;
  display: flex;
  align-items: center;
}

.offer-tile__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.offer-tile__big {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 21px;
  line-height: 1.05;
}

.offer-tile__sub {
  font-size: 12px;
  color: var(--cm-muted);
}

.offer-tile__foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.offer-tile__name {
  display: flex;
  align-items: center;
  gap: 7px;
  font-weight: 800;
  font-size: 15px;
}

.offer-tile__desc {
  font-size: 12.5px;
  line-height: 1.35;
  color: var(--cm-ink-soft);
  text-wrap: pretty;
}

.offer-tile__stock {
  font-size: 11.5px;
  font-weight: 800;
}

.body--dark .offer-tile__stock {
  color: var(--cm-ink-soft) !important;
}

@keyframes offer-shine {
  0% {
    transform: translateX(-140%) skewX(-20deg);
  }
  60%,
  100% {
    transform: translateX(260%) skewX(-20deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .offer-tile__shine {
    display: none;
  }
}
</style>
