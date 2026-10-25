<template>
  <div
    class="streak-flame"
    :class="{ 'streak-flame--out': streak === 0 }"
    :style="{ '--flame': `${flamePx}px` }"
    aria-hidden="true"
  >
    <span class="streak-flame__drop streak-flame__drop--outer" />
    <span class="streak-flame__drop streak-flame__drop--inner" />
  </div>
</template>

<script setup>
/**
 * The streak's flame of the dashboard (the card and its sheet): grey at 0, a little bigger with
 * each streak badge reached (`flameScale()`, docs/GAMIFICATION.md § 4).
 */
import { computed } from 'vue'
import { FLAME_BASE, flameScale } from '@/utils/streak'

const props = defineProps({
  /** The current streak. */
  streak: { type: Number, default: 0 },
  /** The flame's size before any badge, in px. */
  size: { type: Number, default: 30 }
})

const flamePx = computed(() =>
  Math.round(props.size * (flameScale(props.streak) / FLAME_BASE) ** 0.5)
)
</script>

<style scoped lang="scss">
// Two drops, as the celebration screen's flame.
.streak-flame {
  position: relative;
  flex: none;
  width: 50px;
  height: 50px;
  border-radius: 16px;
  background: rgba(255, 138, 61, 0.16);

  &--out {
    filter: grayscale(1);
    opacity: 0.55;
  }
}

.streak-flame__drop {
  position: absolute;
  left: 50%;
  border-radius: 50% 0 50% 50%;
  transform: rotate(-45deg);

  &--outer {
    bottom: calc(50% - var(--flame) * 0.5);
    width: calc(var(--flame) * 0.74);
    height: calc(var(--flame) * 0.74);
    margin-left: calc(var(--flame) * -0.37);
    background: #ff8a3d;
    animation: streak-flame-flicker 1.6s ease-in-out infinite;
  }

  &--inner {
    bottom: calc(50% - var(--flame) * 0.46);
    width: calc(var(--flame) * 0.4);
    height: calc(var(--flame) * 0.4);
    margin-left: calc(var(--flame) * -0.2);
    background: #ffd84d;
  }

  .streak-flame--out & {
    animation: none;
  }
}

@keyframes streak-flame-flicker {
  0%,
  100% {
    transform: rotate(-45deg) scale(1, 1);
  }
  50% {
    transform: rotate(-43deg) scale(0.96, 1.05);
  }
}

@media (prefers-reduced-motion: reduce) {
  .streak-flame__drop {
    animation: none;
  }
}
</style>
