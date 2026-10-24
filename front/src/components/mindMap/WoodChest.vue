<template>
  <span
    class="wood-chest"
    :class="`wood-chest--${state}`"
    :style="{ '--s': size }"
    aria-hidden="true"
  >
    <span v-if="state === 'opened'" class="wood-chest__gem"
      ><CrystalGem :size="24 * size"
    /></span>
    <span class="wood-chest__base">
      <span class="wood-chest__band wood-chest__band--a" />
      <span class="wood-chest__band wood-chest__band--b" />
      <span class="wood-chest__rim wood-chest__rim--top" />
    </span>
    <span class="wood-chest__lid">
      <span class="wood-chest__band wood-chest__band--a" />
      <span class="wood-chest__band wood-chest__band--b" />
      <span class="wood-chest__rim wood-chest__rim--bottom" />
    </span>
    <span v-if="state !== 'opened'" class="wood-chest__lock"><span /></span>
  </span>
</template>

<script setup>
/**
 * A chest of the path (design « Parcours zone »): wooden, with golden bands. Locked: grey. Ready
 * (earned, not opened yet): it glows and hops. Opened: the lid up, a crystal inside.
 */
import CrystalGem from '@/components/shop/CrystalGem.vue'

defineProps({
  /** @type {import('vue').PropType<'locked'|'ready'|'opened'>} */
  state: { type: String, default: 'locked' },
  /** × its 64 × 58 px. */
  size: { type: Number, default: 1 }
})
</script>

<style scoped lang="scss">
$gold: linear-gradient(180deg, #fff1a8, #ffd43b 50%, #e7a900);
$outline: 0 0 0 calc(3px * var(--s)) #1b1530;

.wood-chest {
  position: relative;
  display: inline-block;
  flex: none;
  width: calc(64px * var(--s));
  height: calc(58px * var(--s));
  transform-origin: 50% 100%;

  &--locked {
    filter: grayscale(1) brightness(0.55);
  }

  &--ready {
    filter: drop-shadow(0 0 10px rgba(255, 212, 59, 0.75));
    animation: wood-chest-hop 1.4s ease-in-out infinite;
  }
}

@keyframes wood-chest-hop {
  0%,
  100% {
    transform: translateY(0) rotate(0);
  }
  30% {
    transform: translateY(-6px) rotate(-4deg);
  }
  60% {
    transform: translateY(0) rotate(3deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .wood-chest--ready {
    animation: none;
  }
}

.wood-chest__gem {
  position: absolute;
  left: calc(20px * var(--s));
  top: calc(10px * var(--s));
  z-index: 1;
}

.wood-chest__base,
.wood-chest__lid {
  position: absolute;
  overflow: hidden;
  box-shadow: $outline;
}

.wood-chest__base {
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 2;
  height: calc(32px * var(--s));
  border-radius: calc(4px * var(--s)) calc(4px * var(--s)) calc(10px * var(--s))
    calc(10px * var(--s));
  background: linear-gradient(180deg, #a8642c, #7a4116 60%, #5e3010);
}

.wood-chest__lid {
  left: calc(-1px * var(--s));
  right: calc(-1px * var(--s));
  top: calc(4px * var(--s));
  z-index: 3;
  height: calc(24px * var(--s));
  transform-origin: 8% 100%;
  border-radius: 50% 50% 4px 4px / 80% 80% 4px 4px;
  background: linear-gradient(180deg, #c07a3a, #a8642c 45%, #7a4116);
}

.wood-chest--opened .wood-chest__lid {
  transform: rotate(-32deg) translate(-2px, -8px);
}

.wood-chest__band {
  position: absolute;
  top: 0;
  bottom: 0;
  width: calc(8px * var(--s));
  background: $gold;

  &--a {
    left: calc(10px * var(--s));
  }

  &--b {
    left: calc(46px * var(--s));
  }
}

.wood-chest__lid .wood-chest__band--a {
  left: calc(11px * var(--s));
}

.wood-chest__lid .wood-chest__band--b {
  left: calc(47px * var(--s));
}

.wood-chest__rim {
  position: absolute;
  left: 0;
  right: 0;
  height: calc(5px * var(--s));
  background: $gold;

  &--top {
    top: 0;
  }

  &--bottom {
    bottom: 0;
  }
}

.wood-chest__lock {
  position: absolute;
  left: calc(24px * var(--s));
  top: calc(20px * var(--s));
  z-index: 4;
  display: flex;
  align-items: center;
  justify-content: center;
  width: calc(16px * var(--s));
  height: calc(17px * var(--s));
  border-radius: calc(4px * var(--s)) calc(4px * var(--s)) calc(7px * var(--s))
    calc(7px * var(--s));
  background: $gold;
  box-shadow: 0 0 0 calc(2.5px * var(--s)) #1b1530;

  span {
    width: calc(4px * var(--s));
    height: calc(7px * var(--s));
    border-radius: 2px;
    background: #1b1530;
  }
}
</style>
