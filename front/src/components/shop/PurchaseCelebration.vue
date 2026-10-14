<template>
  <div class="party" data-testid="shop-celebration" aria-live="polite">
    <div class="party__dim" />
    <span
      v-for="(coin, i) in COINS"
      :key="i"
      class="party__coin"
      :style="{
        '--dx': `${coin.dx}px`,
        '--dy': `${coin.dy}px`,
        animationDelay: `${i * 0.045}s`
      }"
      ><CrownCoin :size="22"
    /></span>
    <div
      class="party__rays"
      :style="{
        background: `repeating-conic-gradient(${look.tile}55 0 12deg, transparent 12deg 30deg)`
      }"
    />
    <div
      class="party__tile"
      :style="{ background: look.tile, color: look.tileInk }"
      aria-hidden="true"
    >
      {{ look.glyph }}
    </div>
    <div class="party__text">
      <div class="party__name">
        {{ quantity > 1 ? `${quantity} × ` : '' }}{{ offer.name }}
      </div>
      <div class="party__added">Ajouté à ton inventaire</div>
    </div>
  </div>
</template>

<script setup>
/**
 * After a purchase (design "Boutique"): crowns leave the wallet, the item pops out on rays, then
 * the whole thing fades (2 s, `done` then).
 */
import { computed, onBeforeUnmount, onMounted } from 'vue'
import CrownCoin from '@/components/shop/CrownCoin.vue'
import { lookOf } from '@/utils/shop'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/shop').ShopOffer>} */
  offer: { type: Object, required: true },
  quantity: { type: Number, default: 1 }
})

const emit = defineEmits({ done: () => true })

const COINS = Array.from({ length: 10 }, (_, i) => ({
  dx: -118 + ((i * 23) % 40) - 20,
  dy: 300 + ((i * 17) % 50) - 25
}))

const look = computed(() => lookOf(props.offer))
/** @type {ReturnType<typeof setTimeout>|undefined} */
let timer

onMounted(() => (timer = setTimeout(() => emit('done'), 2000)))
onBeforeUnmount(() => clearTimeout(timer))
</script>

<style scoped lang="scss">
.party {
  position: fixed;
  inset: 0;
  z-index: 6000;
  pointer-events: none;
  animation: party-fade 2s both;
}

.party__dim {
  position: absolute;
  inset: 0;
  background: rgba(27, 21, 48, 0.55);
  animation: party-in 0.2s both;
}

.party__coin {
  position: absolute;
  right: 60px;
  top: 90px;
  animation: party-fly 0.7s cubic-bezier(0.5, 0, 0.6, 1) both;
}

.party__rays,
.party__tile {
  position: absolute;
  left: 50%;
  top: 42%;
}

.party__rays {
  width: 300px;
  height: 300px;
  margin: -150px 0 0 -150px;
  border-radius: 50%;
  mask-image: radial-gradient(circle, #000 30%, transparent 70%);
  animation:
    party-in 0.3s 0.6s both,
    party-ray 8s linear infinite;
}

.party__tile {
  width: 120px;
  height: 120px;
  margin: -60px 0 0 -60px;
  border-radius: 34px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 64px;
  box-shadow: 0 8px 0 rgba(0, 0, 0, 0.2);
  animation: party-pop 0.6s cubic-bezier(0.34, 1.8, 0.64, 1) 0.6s both;
}

.party__text {
  position: absolute;
  left: 0;
  right: 0;
  top: calc(42% + 84px);
  text-align: center;
  color: #fff;
  animation: party-pop 0.5s cubic-bezier(0.34, 1.8, 0.64, 1) 0.8s both;
}

.party__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 26px;
}

.party__added {
  margin-top: 4px;
  font-size: 14px;
  font-weight: 700;
  color: #c6f432;
}

@keyframes party-fade {
  0%,
  80% {
    opacity: 1;
  }
  100% {
    opacity: 0;
  }
}

@keyframes party-in {
  from {
    opacity: 0;
  }
}

@keyframes party-fly {
  0% {
    transform: translate(0, 0) scale(0.6);
    opacity: 0;
  }
  15% {
    opacity: 1;
  }
  100% {
    transform: translate(var(--dx), var(--dy)) scale(1);
    opacity: 0;
  }
}

@keyframes party-ray {
  to {
    transform: rotate(360deg);
  }
}

@keyframes party-pop {
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

@media (prefers-reduced-motion: reduce) {
  .party__coin,
  .party__rays {
    display: none;
  }
}
</style>
