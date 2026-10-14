<template>
  <q-dialog
    :model-value="!!offer"
    :position="$q.screen.lt.md ? 'bottom' : 'standard'"
    @update:model-value="!$event && emit('close')"
  >
    <div v-if="offer" class="sheet" data-testid="shop-sheet">
      <div class="sheet__grip lt-md" aria-hidden="true" />
      <div class="sheet__head">
        <div
          class="sheet__icon"
          :style="{ background: look.tile, color: look.tileInk }"
          aria-hidden="true"
        >
          <span class="sheet__glyph">{{ look.glyph }}</span>
        </div>
        <div class="sheet__title">
          <span class="sheet__kicker" :style="{ color: accent }">{{
            look.kicker
          }}</span>
          <span class="sheet__name">{{ offer.name }}</span>
          <span v-if="offer.items.length === 1" class="sheet__stock"
            >En stock : {{ stock }}</span
          >
        </div>
      </div>

      <ol class="sheet__how">
        <li v-for="(step, i) in how" :key="i">
          <span
            class="sheet__step"
            :style="{ background: look.soft, color: look.accent }"
            >{{ i + 1 }}</span
          ><span>{{ step }}</span>
        </li>
      </ol>

      <div class="sheet__quantity">
        <span>Quantité</span>
        <div class="sheet__stepper">
          <button
            type="button"
            aria-label="Un de moins"
            :disabled="quantity <= 1"
            data-testid="shop-less"
            @click="quantity--"
          >
            −
          </button>
          <span data-testid="shop-quantity">{{ quantity }}</span>
          <button
            type="button"
            aria-label="Un de plus"
            :disabled="quantity >= MAX"
            data-testid="shop-more"
            @click="quantity++"
          >
            ＋
          </button>
        </div>
      </div>

      <div class="sheet__buy">
        <button
          :key="shake"
          type="button"
          class="sheet__btn"
          :class="{
            'sheet__btn--short': !pay.ok,
            'sheet__btn--shake': shake > 0
          }"
          :disabled="busy"
          data-testid="shop-buy"
          @click="buy"
        >
          {{ busy ? 'Un instant…' : pay.label }}
          <template v-if="!busy"
            ><CrownCoin :size="22" />{{ crowns(pay.amount) }}</template
          >
        </button>
        <div
          class="sheet__note"
          :class="{ 'sheet__note--short': !pay.ok || error }"
          data-testid="shop-note"
        >
          {{ error || pay.note }}
        </div>
      </div>
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * The purchase sheet (design "Boutique"): what the offer does, how many, the price or what is
 * missing (the button shakes then), and the balance left. From below on a phone, centred above.
 */
import { computed, ref, watch } from 'vue'
import { useQuasar } from 'quasar'
import CrownCoin from '@/components/shop/CrownCoin.vue'
import { checkout, crowns, howOf, lookOf, stockOf } from '@/utils/shop'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/shop').ShopOffer|null>} */
  offer: { type: Object, default: null },
  balance: { type: Number, required: true },
  /** @type {import('vue').PropType<Record<string, number>>} */
  inventory: { type: Object, required: true },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' }
})

const emit = defineEmits({
  close: () => true,
  /** @param {number} quantity */
  buy: quantity => quantity > 0
})

const MAX = 9

const $q = useQuasar()
const quantity = ref(1)
const shake = ref(0)

watch(
  () => props.offer?.id,
  () => {
    quantity.value = 1
    shake.value = 0
  }
)

const look = computed(() =>
  props.offer ? lookOf(props.offer) : lookOf(/** @type {any} */ ({ items: [] }))
)
const accent = computed(() =>
  $q.dark.isActive ? look.value.tile : look.value.accent
)
const how = computed(() => (props.offer ? howOf(props.offer) : []))
const stock = computed(() =>
  props.offer ? stockOf(props.offer, props.inventory) : 0
)
const pay = computed(() =>
  checkout(props.offer?.price ?? 0, quantity.value, props.balance)
)

function buy() {
  if (!pay.value.ok) {
    shake.value += 1
    return
  }
  emit('buy', quantity.value)
}
</script>

<style scoped lang="scss">
.sheet {
  width: 100%;
  max-width: 480px;
  padding: 10px 18px 26px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  border-radius: 30px 30px 0 0;

  @media (min-width: $breakpoint-md-min) {
    padding: 26px 28px;
    border-radius: 28px;
  }
}

.sheet__grip {
  align-self: center;
  width: 40px;
  height: 5px;
  border-radius: 5px;
  background: var(--cm-dash);
}

.sheet__head {
  display: flex;
  gap: 14px;
  align-items: center;
}

.sheet__icon {
  flex: none;
  width: 76px;
  height: 76px;
  border-radius: 22px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.sheet__glyph {
  display: inline-block;
  font-size: 40px;
  line-height: 1;
  animation:
    sheet-pop 0.55s cubic-bezier(0.34, 1.8, 0.64, 1) both,
    sheet-bob 2.4s ease-in-out 0.6s infinite;
}

.sheet__title {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.sheet__kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.05em;
}

.sheet__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
  line-height: 1.05;
}

.sheet__stock {
  font-size: 12.5px;
  color: var(--cm-muted);
}

.sheet__how {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 8px;

  li {
    display: flex;
    gap: 10px;
    align-items: flex-start;
    font-size: 13.5px;
    line-height: 1.4;
    text-wrap: pretty;
  }
}

.sheet__step {
  flex: none;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  font-size: 11px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
}

.sheet__quantity {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 8px 8px 14px;
  border-radius: 16px;
  background: var(--cm-page);
  font-weight: 700;
  font-size: 14px;
}

.sheet__stepper {
  display: flex;
  align-items: center;
  gap: 6px;

  span {
    width: 30px;
    text-align: center;
    font-weight: 800;
    font-size: 17px;
    font-variant-numeric: tabular-nums;
  }

  button {
    width: 40px;
    height: 40px;
    border: none;
    border-radius: 12px;
    background: var(--cm-surface);
    box-shadow: 0 0 0 1px var(--cm-line);
    color: var(--cm-ink);
    font-size: 20px;
    font-weight: 800;
    cursor: pointer;

    &:disabled {
      opacity: 0.4;
      cursor: default;
    }
  }
}

.sheet__buy {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.sheet__btn {
  height: 56px;
  border: none;
  border-radius: 18px;
  background: #1b1530;
  color: #fff;
  font-weight: 800;
  font-size: 16px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  box-shadow: 0 4px 0 #4c475e;

  &:active:not(:disabled) {
    transform: translateY(4px);
    box-shadow: none;
  }

  &--short {
    background: var(--cm-subtle);
    color: var(--cm-muted);
    box-shadow: 0 4px 0 var(--cm-dash);
  }

  &--shake {
    animation: sheet-shake 0.4s both;
  }
}

.body--dark .sheet__btn:not(.sheet__btn--short) {
  background: #c6f432;
  color: #1b1530;
  box-shadow: 0 4px 0 #5a7a00;
}

.sheet__note {
  min-height: 16px;
  text-align: center;
  font-size: 12.5px;
  font-weight: 700;
  color: var(--cm-muted);

  &--short {
    color: var(--cm-orange-ink);
  }
}

@keyframes sheet-pop {
  0% {
    transform: scale(0) rotate(-20deg);
  }
  55% {
    transform: scale(1.18) rotate(6deg);
  }
  100% {
    transform: none;
  }
}

@keyframes sheet-bob {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-6px);
  }
}

@keyframes sheet-shake {
  0%,
  100% {
    transform: translateX(0);
  }
  20%,
  60% {
    transform: translateX(-7px);
  }
  40%,
  80% {
    transform: translateX(7px);
  }
}
</style>
