<template>
  <div class="brain-map" data-testid="brain-map">
    <div
      class="brain-map__glow"
      :style="{
        background: `radial-gradient(closest-side, ${glowColor}44, transparent)`
      }"
    />
    <div class="brain-map__shadow" />
    <div class="brain-map__image" :style="imageBox">
      <!-- The whole brain, greyed and tinted. -->
      <div class="brain-map__layer">
        <img :src="BRAIN" alt="" draggable="false" class="brain-map__img" />
        <div
          class="brain-map__tint"
          :style="{ background: BASE_TINT, ...maskOf(imageMask) }"
        />
      </div>
      <!-- Each zone in its colour, shown when hovered or chosen. -->
      <div
        v-for="c in ordered"
        :key="c.key"
        class="brain-map__layer brain-map__zone"
        :class="{ 'brain-map__zone--lit': lit(c.key) }"
        :style="maskOf(zoneMask(c.region))"
      >
        <img
          :src="BRAIN"
          alt=""
          draggable="false"
          class="brain-map__img brain-map__img--bright"
        />
        <div
          class="brain-map__tint"
          :style="{ background: c.color, ...maskOf(imageMask) }"
        />
      </div>
    </div>
    <svg
      class="brain-map__svg"
      :viewBox="VIEW_BOX.join(' ')"
      role="group"
      :aria-label="$t('mindMap.page.brainLabel')"
    >
      <defs>
        <clipPath id="brain-map-clip"><path :d="OUTLINE" /></clipPath>
      </defs>
      <g clip-path="url(#brain-map-clip)">
        <path
          v-for="c in ordered"
          :key="c.key"
          :d="c.region"
          fill="transparent"
          class="brain-map__hit"
          :data-testid="`brain-zone-${c.key}`"
          @click.stop="emit('select', c.key)"
          @mouseenter="emit('hover', c.key)"
          @mouseleave="emit('hover', null)"
        />
      </g>
      <g v-for="c in ordered" :key="`b-${c.key}`" pointer-events="none">
        <circle
          :cx="c.badge[0]"
          :cy="c.badge[1]"
          r="36"
          :fill="lit(c.key) ? '#FFFFFF' : INK"
          :stroke="lit(c.key) ? INK : c.color"
          stroke-width="5"
        />
        <text
          :x="c.badge[0]"
          :y="c.badge[1] + 13"
          text-anchor="middle"
          font-weight="800"
          font-size="36"
          :fill="lit(c.key) ? INK : c.color"
        >
          {{ c.num }}
        </text>
      </g>
    </svg>
  </div>
</template>

<script setup>
/**
 * The brain of the mind map (design "Carte mentale"): the picture greyed, each capacity's zone
 * lit in its colour when hovered or chosen, its number on a badge. Clicking a zone chooses its
 * capacity (`select`); hovering it tells the page (`hover`), which lights the card too.
 */
import { computed } from 'vue'
import BRAIN from '@/assets/mindMap/brain-pink.png'
import {
  CAPACITIES,
  IMAGE,
  OUTLINE,
  VIEW_BOX,
  capacity,
  zoneMask
} from '@/utils/mindMap'

const props = defineProps({
  /** @type {import('vue').PropType<string|null>} the capacity chosen */
  selected: { type: String, default: null },
  /** @type {import('vue').PropType<string|null>} the capacity under the pointer */
  hovered: { type: String, default: null }
})

const emit = defineEmits({
  select: key => typeof key === 'string',
  hover: key => key === null || typeof key === 'string'
})

/** Dark ink of the badges. */
const INK = '#0E0B16'
/** The colour of the brain at rest. */
const BASE_TINT = '#5B5280'
/** The zones drawn back to front, as in the design (the smaller ones on top). */
const ORDER = [
  'strategy',
  'calculation',
  'visualization',
  'speed',
  'patterns',
  'composure'
]

const ordered = ORDER.map(
  key => /** @type {import('@/utils/mindMap').Capacity} */ (capacity(key))
)

/** The tint is kept inside the drawing: the picture itself is its mask. */
const imageMask = `url("${BRAIN}")`

/** The picture is larger than the part shown: placed so that VIEW_BOX fills the frame. */
const imageBox = {
  left: `${(-VIEW_BOX[0] / VIEW_BOX[2]) * 100}%`,
  top: `${(-VIEW_BOX[1] / VIEW_BOX[3]) * 100}%`,
  width: `${(IMAGE.width / VIEW_BOX[2]) * 100}%`,
  height: `${(IMAGE.height / VIEW_BOX[3]) * 100}%`
}

const glowColor = computed(
  () => CAPACITIES.find(c => c.key === props.selected)?.color ?? '#6A4CFF'
)

/** @param {string} key */
function lit(key) {
  return props.selected === key || props.hovered === key
}

/** @param {string} mask a CSS image */
function maskOf(mask) {
  return {
    WebkitMaskImage: mask,
    maskImage: mask,
    WebkitMaskSize: '100% 100%',
    maskSize: '100% 100%',
    WebkitMaskRepeat: 'no-repeat',
    maskRepeat: 'no-repeat'
  }
}
</script>

<style scoped lang="scss">
@keyframes brain-float {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-8px);
  }
}

.brain-map {
  position: relative;
  width: 100%;
  max-width: 640px;
  aspect-ratio: 1214 / 1014;
  margin: 0 auto;
  animation: brain-float 5s ease-in-out infinite;

  @media (prefers-reduced-motion: reduce) {
    animation: none;
  }
}

.brain-map__glow {
  position: absolute;
  inset: -12%;
  border-radius: 50%;
  transition: background 0.4s;
  pointer-events: none;
}

.brain-map__shadow {
  position: absolute;
  left: 28%;
  right: 22%;
  bottom: -3%;
  height: 4%;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.25);
}

.brain-map__image {
  position: absolute;
  pointer-events: none;
}

.brain-map__layer {
  position: absolute;
  inset: 0;
  isolation: isolate;
}

.brain-map__img {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  filter: grayscale(1) brightness(1.1) contrast(1.2);

  &--bright {
    filter: grayscale(1) brightness(1.6) contrast(1.2);
  }
}

.brain-map__tint {
  position: absolute;
  inset: 0;
  mix-blend-mode: multiply;
}

.brain-map__zone {
  opacity: 0;
  transition: opacity 0.35s;

  &--lit {
    opacity: 1;
  }
}

.brain-map__svg {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  overflow: visible;
  font-family: inherit;
}

.brain-map__hit {
  cursor: pointer;
}
</style>
