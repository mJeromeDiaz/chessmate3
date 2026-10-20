<template>
  <div
    class="board-distractions"
    :class="{ 'board-distractions--wobble': wobbling }"
    :data-distraction="level"
    data-testid="board-distractions"
  >
    <slot />
    <div
      v-if="running && balls.length"
      class="board-distractions__balls"
      aria-hidden="true"
    >
      <span
        v-for="(glyph, i) in balls"
        :key="i"
        class="board-distractions__ball"
        :style="{
          animationDelay: `${i * 0.9}s`,
          top: `${20 + ((i * 27) % 60)}%`
        }"
        >{{ glyph }}</span
      >
    </div>
  </div>
</template>

<script setup>
/**
 * The distractions of a mind map boss around its board (docs/MIND_MAP.md, § 2): none in the
 * exercises, `light` (the board wobbles now and then, two pieces drift across it), `medium` (more
 * often, three pieces, and a stray move sound now and then while `active`). Nothing moves when the
 * system asks for less motion; the sound follows the player's « Sons » preference. The Gargouille's
 * `strong` ones come with it.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { playMoveSound } from '@/utils/chess/moveSounds'

const props = defineProps({
  /** @type {import('vue').PropType<'none'|'light'|'medium'|'strong'>} */
  level: { type: String, default: 'none' },
  /** While the player memorizes, listens or answers. */
  active: { type: Boolean, default: false }
})

/** By level: how often something happens (ms), its chance then, the pieces drifting, a sound. */
const RHYTHMS = {
  light: { every: 4000, chance: 0.35, balls: ['♞', '♝'], sound: false },
  medium: { every: 2500, chance: 0.5, balls: ['♞', '♝', '♜'], sound: true },
  strong: { every: 1500, chance: 0.7, balls: ['♞', '♝', '♜', '♛'], sound: true }
}
const WOBBLE_MS = 900

const auth = useAuthStore()
const wobbling = ref(false)
/** @type {ReturnType<typeof setInterval>|undefined} */
let ticker
/** @type {ReturnType<typeof setTimeout>|undefined} */
let wobbleTimer

const rhythm = computed(
  () => RHYTHMS[/** @type {keyof typeof RHYTHMS} */ (props.level)] ?? null
)
const running = computed(() => props.active && rhythm.value !== null)
const balls = computed(() => rhythm.value?.balls ?? [])

function tick() {
  const r = rhythm.value
  if (!r || Math.random() > r.chance) return
  if (Math.random() < 0.6) {
    wobbling.value = true
    clearTimeout(wobbleTimer)
    wobbleTimer = setTimeout(() => (wobbling.value = false), WOBBLE_MS)
  } else if (r.sound && auth.profile?.moveSound === true) {
    playMoveSound(Math.random() < 0.5 ? 'move' : 'capture')
  }
}

watch(
  running,
  on => {
    clearInterval(ticker)
    if (on && rhythm.value) ticker = setInterval(tick, rhythm.value.every)
    else wobbling.value = false
  },
  { immediate: true }
)

onBeforeUnmount(() => {
  clearInterval(ticker)
  clearTimeout(wobbleTimer)
})
</script>

<style scoped lang="scss">
.board-distractions {
  position: relative;

  &--wobble {
    animation: board-distractions-wobble 0.3s ease-in-out 3;
  }
}

.board-distractions__balls {
  position: absolute;
  inset: 0;
  overflow: hidden;
  pointer-events: none;
}

.board-distractions__ball {
  position: absolute;
  left: -15%;
  font-size: 44px;
  opacity: 0.7;
  animation: board-distractions-drift 3.2s linear infinite;
}

@keyframes board-distractions-wobble {
  0%,
  100% {
    transform: rotate(0);
  }
  25% {
    transform: rotate(-1.5deg) translateX(-4px);
  }
  75% {
    transform: rotate(1.5deg) translateX(4px);
  }
}

@keyframes board-distractions-drift {
  0% {
    transform: translate(0, 0) rotate(0);
  }
  50% {
    transform: translate(65vw, -80px) rotate(200deg);
  }
  100% {
    transform: translate(130vw, 0) rotate(400deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .board-distractions--wobble {
    animation: none;
  }

  .board-distractions__balls {
    display: none;
  }
}
</style>
