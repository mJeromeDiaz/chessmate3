<template>
  <div class="prof-stage">
    <div v-if="rings" class="prof-stage__rings" aria-hidden="true">
      <div class="prof-stage__disc" :style="{ background: disc }" />
      <div class="prof-stage__dash" :style="{ borderColor: dash }" />
      <div
        v-for="i in 3"
        :key="i"
        class="prof-stage__ring"
        :style="{ borderColor: ring, animationDelay: `${(i - 1) * 0.8}s` }"
      />
    </div>
    <div
      class="prof-stage__figure"
      :class="`prof-stage__figure--${mood}`"
      :style="{
        transform: shown
          ? 'translateY(0) scale(1)'
          : 'translateY(360px) scale(.6)'
      }"
    >
      <img
        v-if="image"
        :key="image"
        :src="image"
        alt=""
        class="prof-stage__img"
        :class="{ 'prof-stage__img--swap': swap }"
      />
      <div v-else class="prof-stage__glyph" :style="{ color: glyphInk }">{{
        glyph
      }}</div>
      <slot />
    </div>
  </div>
</template>

<script setup>
/**
 * The professor on an end-of-run screen, risen from below onto a disc with pulsing rings (designs
 * "Résultat de leçon", "Corriger ses erreurs"): bobbing when pleased, shaking with rage after a
 * failed lesson. A professor without a picture shows its glyph.
 */
defineProps({
  image: { type: String, default: '' },
  glyph: { type: String, default: '♞︎' },
  glyphInk: { type: String, default: '#1B1530' },
  /** Risen into place. */
  shown: { type: Boolean, default: false },
  rings: { type: Boolean, default: false },
  disc: { type: String, default: '#5A3BE0' },
  ring: { type: String, default: '#FFFFFF' },
  dash: { type: String, default: 'rgba(27,21,48,.25)' },
  /** @type {import('vue').PropType<'still'|'bob'|'rage'>} */
  mood: { type: String, default: 'still' },
  /** The picture pops in (a change of pose). */
  swap: { type: Boolean, default: false }
})
</script>

<style scoped lang="scss">
.prof-stage {
  position: relative;
  height: 260px;
  display: flex;
  justify-content: center;
  align-items: flex-end;
  flex: none;
}

.prof-stage__rings {
  position: absolute;
  left: 50%;
  bottom: 4px;
  width: 240px;
  height: 240px;
  margin-left: -120px;
  pointer-events: none;
}

.prof-stage__disc {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  opacity: 0.7;
}

.prof-stage__dash {
  position: absolute;
  inset: -18px;
  border-radius: 50%;
  border: 3px dashed;
  animation: ps-spin 18s linear infinite;
}

.prof-stage__ring {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  border: 4px solid;
  animation: ps-ring 2.4s ease-out infinite;
}

.prof-stage__figure {
  position: relative;
  width: 230px;
  height: 240px;
  transform-origin: 50% 100%;
  transition: transform 0.75s cubic-bezier(0.34, 1.7, 0.64, 1);

  &--bob > * {
    animation: ps-bob 2.2s cubic-bezier(0.34, 1.56, 0.64, 1) infinite;
  }

  &--rage > * {
    animation: ps-rage 0.35s linear infinite;
  }
}

.prof-stage__img {
  position: absolute;
  left: 0;
  bottom: 0;
  width: 100%;
  height: 100%;
  object-fit: contain;
  object-position: center bottom;
  transform-origin: 50% 100%;

  &--swap {
    animation: ps-swap 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
}

.prof-stage__glyph {
  position: absolute;
  left: 50%;
  bottom: 20px;
  width: 190px;
  height: 190px;
  margin-left: -95px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.85);
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  font-size: 120px;
  line-height: 1;
}

@keyframes ps-spin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes ps-ring {
  from {
    transform: scale(0.55);
    opacity: 0.7;
  }
  to {
    transform: scale(1.7);
    opacity: 0;
  }
}

@keyframes ps-bob {
  0%,
  100% {
    transform: translateY(0) rotate(0);
  }
  30% {
    transform: translateY(-14px) rotate(-2deg);
  }
  55% {
    transform: translateY(0);
  }
  70% {
    transform: translateY(-5px) rotate(1deg);
  }
}

@keyframes ps-rage {
  0%,
  100% {
    transform: translate(0, 0) rotate(0);
  }
  20% {
    transform: translate(-3px, 1px) rotate(-1.5deg);
  }
  40% {
    transform: translate(3px, -1px) rotate(1.5deg);
  }
  60% {
    transform: translate(-2px, 0) rotate(-1deg);
  }
  80% {
    transform: translate(2px, 1px) rotate(1deg);
  }
}

@keyframes ps-swap {
  0% {
    transform: scale(1);
  }
  35% {
    transform: scale(0.86, 1.1);
  }
  70% {
    transform: scale(1.06, 0.95);
  }
  100% {
    transform: scale(1);
  }
}

@media (prefers-reduced-motion: reduce) {
  .prof-stage * {
    animation: none !important;
    transition: none !important;
  }
}
</style>
