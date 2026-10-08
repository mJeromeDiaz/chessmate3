<template>
  <div class="auth-hero" :class="`auth-hero--p${step}`">
    <div class="auth-hero__shade" />
    <div class="auth-hero__rays" />
    <div class="auth-hero__glow" />
    <FloatingPieces v-if="step >= 3" :count="12" class="auth-hero__drift" />

    <div class="auth-hero__head">
      <router-link
        to="/"
        class="auth-hero__mark"
        aria-label="Don't Stay Rooky — accueil"
      >
        <span
          v-for="(l, i) in MARK"
          :key="i"
          class="auth-hero__mark-ch"
          :class="{ 'auth-hero__mark-ch--lime': l.lime }"
          :style="{ transitionDelay: `${i * 0.04}s` }"
          >{{ l.ch }}</span
        >
      </router-link>
      <div class="auth-hero__words">
        <div v-for="(w, i) in WORDS" :key="i" class="auth-hero__word-clip">
          <div
            class="auth-hero__word"
            :style="{ transitionDelay: `${i * 0.08}s` }"
            >{{ w }}</div
          >
        </div>
      </div>
      <div class="auth-hero__chips">
        <span
          v-for="(c, i) in CHIPS"
          :key="c"
          class="auth-hero__chip"
          :style="{ transitionDelay: `${i * 0.1}s` }"
          >{{ c }}</span
        >
      </div>
    </div>

    <div
      v-for="prof in cast"
      :key="prof.id"
      class="auth-hero__slot"
      :class="[
        `auth-hero__slot--${prof.id}`,
        { 'auth-hero__slot--small': prof.small }
      ]"
    >
      <button
        type="button"
        class="auth-hero__prof"
        :aria-label="`Faire sauter ${prof.name}`"
        @click="hop(prof.id)"
      >
        <img
          :key="hops[prof.id]"
          :src="prof.src"
          :alt="prof.name"
          class="auth-hero__img"
          :class="
            hops[prof.id]
              ? `auth-hero__img--hop${hops[prof.id] % 2 ? 'a' : 'b'}`
              : ''
          "
        />
      </button>
    </div>

    <div class="auth-hero__bubble">Salut ! ♟︎</div>
  </div>
</template>

<script setup>
/**
 * The professors' hero of the sign-in screens (design "Connexion"): the purple flood, slow rays,
 * drifting pieces, the brand dropping in, then the three professors popping up (tap one: it hops).
 * Narrow screens: a 250 px band with busts and a "Salut !" bubble; wide ones: a full-height column
 * with full-length pictures, a tagline and the professors' chips. Pictures missing from
 * src/assets/profs fall back to existing poses, shown smaller. Reduced motion: final state at once.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import FloatingPieces from '@/components/training/result/FloatingPieces.vue'
import { profImage } from '@/utils/prof/images'

const props = defineProps({
  /** Wide layout (full-length pictures). */
  wide: { type: Boolean, default: false }
})

const MARK = [..."Don't Stay "]
  .map(ch => ({ ch, lime: false }))
  .concat([...'Rooky'].map(ch => ({ ch, lime: true })))
const WORDS = 'Tes profs d’échecs t’attendent.'.split(' ')
const CHIPS = ['♛︎ Lizy · Finales', '♚︎ Aaron · Répertoire', '♝︎ Albert · Puzzles']

/** Intro steps (ms after mounting): flood, brand and profs, rays and form, bubble and chips. */
const STEPS = [
  [80, 1],
  [600, 2],
  [1200, 3],
  [1700, 4]
]

/**
 * @param {string} preferred the design's picture
 * @param {string} fallback an existing pose
 */
function picture(preferred, fallback) {
  const src = profImage(preferred)
  return src ? { src, small: false } : { src: profImage(fallback), small: true }
}

const cast = computed(() => {
  const size = props.wide ? 'full' : 'bust'
  return [
    { id: 'lizy', name: 'Lizy', ...picture(`lizy-${size}`, 'lizy-joy') },
    {
      id: 'albert',
      name: 'Albert Stein',
      ...picture(`albert-${size}`, 'albert-v2-wow')
    },
    { id: 'aaron', name: 'Aaron', ...picture(`aaron-${size}`, 'aaron-bust') }
  ].filter(prof => prof.src)
})

const step = ref(0)
const hops = reactive({ lizy: 0, albert: 0, aaron: 0 })
/** @type {ReturnType<typeof setTimeout>[]} */
let timers = []

/** @param {'lizy'|'albert'|'aaron'} id */
function hop(id) {
  hops[id]++
}

onMounted(() => {
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    step.value = 4
    return
  }
  timers = STEPS.map(([ms, p]) => setTimeout(() => (step.value = p), ms))
})

onBeforeUnmount(() => timers.forEach(clearTimeout))
</script>

<style scoped lang="scss">
.auth-hero {
  position: relative;
  height: 250px;
  overflow: hidden;
  background: #8b6bff;
  color: #fff;
  clip-path: circle(0% at 50% 100%);
  transition: clip-path 0.8s cubic-bezier(0.5, 0, 0.2, 1);

  &:not(.auth-hero--p0) {
    clip-path: circle(150% at 50% 100%);
  }
}

.auth-hero__shade {
  position: absolute;
  inset: 0 0 0 auto;
  width: 65%;
  background: #5a3be0;
  opacity: 0.6;
  mask-image: linear-gradient(to right, transparent, #000);
}

.auth-hero__rays {
  position: absolute;
  left: 50%;
  bottom: -150px;
  width: 420px;
  height: 420px;
  margin-left: -210px;
  border-radius: 50%;
  background: repeating-conic-gradient(
    rgba(255, 255, 255, 0.16) 0 10deg,
    transparent 10deg 24deg
  );
  mask-image: radial-gradient(circle, #000 20%, transparent 68%);
  opacity: 0;
  transition: opacity 0.6s;

  .auth-hero--p3 &,
  .auth-hero--p4 & {
    opacity: 1;
    animation: auth-spin 30s linear infinite;
  }
}

.auth-hero__glow {
  position: absolute;
  left: 50%;
  bottom: -120px;
  width: 360px;
  height: 300px;
  transform: translateX(-50%);
  background: radial-gradient(
    circle,
    rgba(255, 255, 255, 0.3),
    transparent 65%
  );
  pointer-events: none;
}

.auth-hero__head {
  position: absolute;
  left: 22px;
  top: 18px;
  right: 22px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.auth-hero__mark {
  display: flex;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 24px;
  color: #fff;
  text-decoration: none;
  white-space: pre;
}

.auth-hero__mark-ch {
  display: inline-block;
  opacity: 0;
  transform: translateY(-30px) rotate(-20deg);
  transition:
    transform 0.5s cubic-bezier(0.34, 1.9, 0.64, 1),
    opacity 0.2s;

  &:nth-child(even) {
    transform: translateY(-30px) rotate(20deg);
  }

  &--lime {
    color: #c6f432;
  }

  .auth-hero--p2 &,
  .auth-hero--p3 &,
  .auth-hero--p4 & {
    opacity: 1;
    transform: none;
  }
}

.auth-hero__words,
.auth-hero__chips {
  display: none;
}

.auth-hero__slot {
  position: absolute;
  bottom: -4px;

  &--lizy {
    left: 6px;
  }

  &--albert {
    right: 4px;
  }

  &--aaron {
    left: 50%;
    bottom: -6px;
    transform: translateX(-50%);
  }
}

.auth-hero__prof {
  display: block;
  padding: 0;
  border: 0;
  background: none;
  cursor: pointer;
  transform-origin: 50% 100%;
  transform: translateY(190px) scale(0.7);
  transition: transform 0.7s cubic-bezier(0.34, 1.8, 0.64, 1);
  transition-delay: 0.05s;

  .auth-hero--p2 &,
  .auth-hero--p3 &,
  .auth-hero--p4 & {
    transform: none;
  }

  .auth-hero__slot--albert & {
    transition-delay: 0.18s;
  }

  .auth-hero__slot--aaron & {
    transition-delay: 0.34s;
  }
}

.auth-hero__img {
  display: block;
  height: 150px;
  transform-origin: 50% 100%;

  .auth-hero__slot--aaron & {
    height: 176px;
  }

  .auth-hero__slot--small & {
    height: 120px;
  }

  .auth-hero--p4 & {
    animation: auth-bob 2.6s ease-in-out infinite;
  }

  .auth-hero--p4 .auth-hero__slot--lizy & {
    animation-delay: -0.3s;
  }

  .auth-hero--p4 .auth-hero__slot--albert & {
    animation-delay: -0.7s;
  }

  &--hopa,
  &--hopb {
    animation: auth-hop-a 0.7s cubic-bezier(0.3, 0.7, 0.4, 1) !important;
  }

  &--hopb {
    animation-name: auth-hop-b !important;
  }
}

.auth-hero__bubble {
  position: absolute;
  left: calc(50% + 48px);
  top: 96px;
  padding: 7px 11px;
  border-radius: 16px 16px 16px 4px;
  background: #fff;
  color: #1b1530;
  font-weight: 700;
  font-size: 13px;
  white-space: nowrap;
  transform-origin: 0 100%;
  transform: scale(0);
  transition: transform 0.45s cubic-bezier(0.34, 2, 0.64, 1);

  .auth-hero--p4 & {
    transform: scale(1) rotate(-3deg);
  }
}

// Wide screens: the hero is the left column, full height.
@media (min-width: 1024px) {
  .auth-hero {
    height: 100%;
    min-height: 100vh;
    clip-path: circle(0% at 0% 100%);
    transition-duration: 0.9s;

    &:not(.auth-hero--p0) {
      clip-path: circle(150% at 0% 100%);
    }
  }

  .auth-hero__shade {
    width: 70%;
  }

  .auth-hero__rays {
    bottom: -300px;
    width: 820px;
    height: 820px;
    margin-left: -410px;
    background: repeating-conic-gradient(
      rgba(255, 255, 255, 0.14) 0 9deg,
      transparent 9deg 22.5deg
    );
    mask-image: radial-gradient(circle, #000 18%, transparent 66%);
  }

  .auth-hero__glow {
    bottom: -160px;
    width: 640px;
    height: 520px;
  }

  .auth-hero__head {
    left: 44px;
    top: 40px;
    right: 44px;
  }

  .auth-hero__mark {
    font-size: 26px;
  }

  .auth-hero__words {
    display: flex;
    flex-wrap: wrap;
    column-gap: 11px;
    max-width: 440px;
  }

  .auth-hero__word-clip {
    overflow: hidden;
    padding-bottom: 3px;
  }

  .auth-hero__word {
    font-family: var(--cm-heading);
    font-weight: 800;
    font-size: 40px;
    line-height: 1.05;
    transform: translateY(115%);
    transition: transform 0.6s cubic-bezier(0.2, 0.9, 0.3, 1.2);

    .auth-hero--p2 &,
    .auth-hero--p3 &,
    .auth-hero--p4 & {
      transform: none;
    }
  }

  .auth-hero__chips {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
  }

  .auth-hero__chip {
    padding: 6px 12px;
    border-radius: 999px;
    background: #fff;
    color: #1b1530;
    font-weight: 700;
    font-size: 13px;
    transform: scale(0) rotate(-15deg);
    transition: transform 0.45s cubic-bezier(0.34, 2, 0.64, 1);

    .auth-hero--p4 & {
      transform: none;
    }
  }

  .auth-hero__slot {
    bottom: -30px;

    &--lizy {
      left: 10px;
    }

    &--albert {
      right: 0;
    }

    &--aaron {
      bottom: -40px;
    }
  }

  .auth-hero__prof {
    transform: translateY(460px) scale(0.7);
    transition-duration: 0.75s;
    transition-delay: 0.1s;

    .auth-hero__slot--albert & {
      transition-delay: 0.24s;
    }

    .auth-hero__slot--aaron & {
      transition-delay: 0.4s;
    }
  }

  .auth-hero__img {
    height: 360px;

    .auth-hero__slot--aaron & {
      height: 440px;
    }

    .auth-hero__slot--small & {
      height: 240px;
    }

    .auth-hero__slot--aaron.auth-hero__slot--small & {
      height: 300px;
    }
  }

  .auth-hero__bubble {
    display: none;
  }
}

@keyframes auth-spin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes auth-bob {
  0%,
  100% {
    transform: translateY(0) rotate(0);
  }
  50% {
    transform: translateY(-6px) rotate(-1.5deg);
  }
}

@keyframes auth-hop-a {
  0%,
  100% {
    transform: translateY(0) scale(1);
  }
  30% {
    transform: translateY(-34px) scale(1.06, 0.95) rotate(-4deg);
  }
  55% {
    transform: translateY(0) scale(1.08, 0.9);
  }
  75% {
    transform: translateY(-8px) scale(0.98, 1.03);
  }
}

@keyframes auth-hop-b {
  0%,
  100% {
    transform: translateY(0) scale(1);
  }
  30% {
    transform: translateY(-34px) scale(1.06, 0.95) rotate(4deg);
  }
  55% {
    transform: translateY(0) scale(1.08, 0.9);
  }
  75% {
    transform: translateY(-8px) scale(0.98, 1.03);
  }
}

@media (prefers-reduced-motion: reduce) {
  .auth-hero,
  .auth-hero * {
    transition: none !important;
    animation: none !important;
  }
}
</style>
