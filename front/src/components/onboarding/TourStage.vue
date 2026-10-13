<template>
  <div class="tour-stage" :style="{ '--bg': theme.bg, '--deep': theme.deep }">
    <div class="tour-stage__shade" aria-hidden="true" />
    <div class="tour-stage__glow" aria-hidden="true" />
    <slot />
    <div class="tour-stage__prof">
      <ProfStage
        :key="`prof-${stageKey}`"
        :image="image"
        :glyph="glyph || '♞︎'"
        :glyph-ink="glyph ? '#FFFFFF' : '#1B1530'"
        :shown="risen"
        rings
        :disc="theme.deep"
        :mood="risen ? 'bob' : 'still'"
      />
      <span v-if="mystery" class="tour-stage__mystery" aria-hidden="true"
        >?</span
      >
    </div>
    <h1
      :key="`title-${stageKey}`"
      class="tour-stage__title"
      :aria-label="title.join(' ')"
    >
      <span
        v-for="(line, row) in letters"
        :key="row"
        class="tour-stage__line"
        aria-hidden="true"
      >
        <span
          v-for="(l, i) in line"
          :key="i"
          class="tour-stage__letter"
          :style="{
            color: row ? theme.line2 : theme.line1,
            '--r': l.r,
            animationDelay: l.delay
          }"
          >{{ l.ch }}</span
        >
      </span>
    </h1>
  </div>
</template>

<script setup>
/**
 * The guided tour's stage (design "Visite guidée"): the screen's colour, the professor risen onto
 * a disc with pulsing rings, the title falling letter by letter. A new `stageKey` plays it again.
 */
import { computed, onMounted, ref, watch } from 'vue'
import ProfStage from '@/components/training/result/ProfStage.vue'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/onboarding').TourTheme>} */
  theme: { type: Object, required: true },
  /** The picture's URL ('' shows the glyph). */
  image: { type: String, default: '' },
  glyph: { type: String, default: '' },
  /** @type {import('vue').PropType<[string, string]>} */
  title: { type: Array, required: true },
  /** A professor in the shadow (a question mark next to the glyph). */
  mystery: { type: Boolean, default: false },
  stageKey: { type: String, required: true }
})

const risen = ref(false)

/** Rises again on each new screen. */
function rise() {
  risen.value = false
  requestAnimationFrame(() => requestAnimationFrame(() => (risen.value = true)))
}

onMounted(rise)
watch(() => props.stageKey, rise)

const letters = computed(() => {
  const [l1, l2] = props.title
  return [l1, l2].map((text, row) =>
    text.split('').map((ch, i) => {
      const k = i + (row ? l1.length : 0)
      return { ch, r: `${k % 2 ? 14 : -14}deg`, delay: `${k * 0.035}s` }
    })
  )
})
</script>

<style scoped lang="scss">
.tour-stage {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  overflow: hidden;
  background: var(--bg);
  transition: background 0.6s ease;
}

.tour-stage__shade {
  position: absolute;
  inset: 0 0 40% 0;
  background: var(--deep);
  opacity: 0.45;
  mask-image: linear-gradient(to top, transparent, #000);
  transition: background 0.6s ease;
}

.tour-stage__glow {
  position: absolute;
  left: 50%;
  top: 4%;
  width: 480px;
  height: 480px;
  margin-left: -240px;
  background: radial-gradient(
    circle,
    rgba(255, 255, 255, 0.3),
    transparent 62%
  );
  pointer-events: none;
}

.tour-stage__prof {
  position: relative;
  height: 210px;
  width: 240px;
  display: flex;
  justify-content: center;
  align-items: flex-end;

  > * {
    transform: scale(0.8);
    transform-origin: 50% 100%;
  }
}

.tour-stage__mystery {
  position: absolute;
  right: 34px;
  top: 30px;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 54px;
  color: #c6f432;
  transform: rotate(12deg);
  animation: tour-wobble 2.4s ease-in-out infinite;
}

.tour-stage__title {
  position: relative;
  margin: 6px 12px 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  font-size: inherit;
  line-height: 1;
}

.tour-stage__line {
  display: flex;
  justify-content: center;
}

.tour-stage__letter {
  display: inline-block;
  white-space: pre;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 40px;
  line-height: 1.02;
  letter-spacing: -0.02em;
  animation: tour-letter 0.6s cubic-bezier(0.34, 1.9, 0.64, 1) both;
}

@media (min-width: $breakpoint-md-min) {
  .tour-stage__prof {
    height: 300px;
    width: 300px;

    > * {
      transform: scale(1.15);
    }
  }

  .tour-stage__mystery {
    right: 20px;
    font-size: 72px;
  }

  .tour-stage__letter {
    font-size: 58px;
  }
}

@keyframes tour-letter {
  0% {
    transform: translateY(-70px) rotate(var(--r)) scale(0.5);
    opacity: 0;
  }
  100% {
    transform: none;
    opacity: 1;
  }
}

@keyframes tour-wobble {
  0%,
  100% {
    transform: rotate(12deg);
  }
  50% {
    transform: rotate(-6deg) translateY(-6px);
  }
}

@media (prefers-reduced-motion: reduce) {
  .tour-stage__letter,
  .tour-stage__mystery {
    animation: none;
  }
}
</style>
