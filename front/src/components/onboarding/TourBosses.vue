<template>
  <div class="tour-bosses tour-in" style="animation-delay: 0.15s">
    <div
      class="tour-bosses__window"
      data-testid="tour-bosses"
      @pointerdown="onDown"
      @pointerup="onUp"
      @pointercancel="onCancel"
    >
      <div
        class="tour-bosses__track"
        :style="{ '--index': modelValue }"
        aria-live="polite"
      >
        <button
          v-for="(boss, i) in TOUR_BOSSES"
          :key="boss.id"
          type="button"
          class="tour-bosses__card"
          :class="{
            'tour-bosses__card--on': i === modelValue,
            'tour-bosses__card--others': !boss.character
          }"
          :style="{
            '--dark': boss.dark,
            '--glow': boss.glow,
            '--accent': boss.accent
          }"
          :aria-label="tourBossName(boss)"
          :aria-current="i === modelValue"
          :data-testid="`tour-boss-${boss.id}`"
          @click="onCard(i)"
        >
          <span class="tour-bosses__glow" aria-hidden="true" />
          <img
            v-if="tourBossImage(boss)"
            :src="tourBossImage(boss)"
            alt=""
            class="tour-bosses__img"
            draggable="false"
          />
          <span v-else class="tour-bosses__shadow" aria-hidden="true">?</span>
          <span class="tour-bosses__text">
            <span class="tour-bosses__name">{{ tourBossName(boss) }}</span>
            <span class="tour-bosses__place">{{ tourBossPlace(boss) }}</span>
          </span>
          <span class="tour-bosses__reward">
            <CrystalGem :size="13" />{{
              boss.crystals ? `+${boss.crystals}` : '???'
            }}
          </span>
        </button>
      </div>
    </div>

    <div class="tour-bosses__nav">
      <button
        type="button"
        class="tour-bosses__arrow"
        :aria-label="$t('onboarding.bosses.previous')"
        data-testid="tour-boss-previous"
        @click="pick(modelValue - 1)"
      >
        ‹
      </button>
      <span class="tour-bosses__hint">{{ $t('onboarding.bosses.hint') }}</span>
      <button
        type="button"
        class="tour-bosses__arrow"
        :aria-label="$t('onboarding.bosses.next')"
        data-testid="tour-boss-next"
        @click="pick(modelValue + 1)"
      >
        ›
      </button>
    </div>
  </div>
</template>

<script setup>
/**
 * The tour's bosses (design « Visite guidée », « Tes pires ennemis »): a strip of cards, the boss
 * shown in the middle, its reward in crystals, going round by itself every few seconds until the
 * player takes over (arrows, a card, a swipe); the last card, the others in the shadow. No count
 * nor dots: there are always more. Less motion asked: it never moves by itself.
 */
import { onBeforeUnmount, onMounted } from 'vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import {
  TOUR_BOSSES,
  tourBossImage,
  tourBossName,
  tourBossPlace
} from '@/utils/onboarding'

/** The next boss comes after this long, until the player takes over. */
const AUTO_MS = 4200
/** A swipe: this far at least. */
const SWIPE_PX = 40

const props = defineProps({
  /** The boss shown, an index of TOUR_BOSSES. */
  modelValue: { type: Number, required: true }
})

const emit = defineEmits({
  /** @param {number} index */
  'update:modelValue': index => Number.isInteger(index)
})

/** @type {ReturnType<typeof setInterval>|null} */
let timer = null
/** @type {number|null} */
let downX = null
/** A swipe just ended: the click it makes on a card is not a choice. */
let swiped = false

/** @param {number} index any: it wraps around */
function show(index) {
  const n = TOUR_BOSSES.length
  emit('update:modelValue', ((index % n) + n) % n)
}

function stop() {
  if (timer) clearInterval(timer)
  timer = null
}

/** The player's choice: no more going round by itself. @param {number} index */
function pick(index) {
  stop()
  show(index)
}

/** @param {number} index */
function onCard(index) {
  if (swiped) return
  pick(index)
}

/** @param {PointerEvent} event */
function onDown(event) {
  downX = event.clientX
  swiped = false
}

/** @param {PointerEvent} event */
function onUp(event) {
  if (downX === null) return
  const dx = event.clientX - downX
  downX = null
  if (Math.abs(dx) < SWIPE_PX) return
  swiped = true
  pick(props.modelValue + (dx < 0 ? 1 : -1))
}

function onCancel() {
  downX = null
}

onMounted(() => {
  const still = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
  if (!still) timer = setInterval(() => show(props.modelValue + 1), AUTO_MS)
})
onBeforeUnmount(stop)
</script>

<style scoped lang="scss">
.tour-bosses {
  --card-w: 140px;
  --card-h: 146px;
  --gap: 10px;
  display: flex;
  flex-direction: column;
  gap: 8px;

  @media (min-width: $breakpoint-md-min) {
    --card-w: 172px;
    --card-h: 180px;
    --gap: 14px;
  }
}

.tour-bosses__window {
  position: relative;
  height: calc(var(--card-h) + 10px);
  margin: 0 -16px;
  // clip, not hidden: a card focused off screen must not scroll the strip.
  overflow: clip;
  touch-action: pan-y;

  @media (min-width: $breakpoint-md-min) {
    margin: 0 -36px;
  }
}

.tour-bosses__track {
  position: absolute;
  top: 4px;
  left: 50%;
  display: flex;
  gap: var(--gap);
  transform: translateX(
    calc(var(--card-w) / -2 - var(--index) * (var(--card-w) + var(--gap)))
  );
  transition: transform 0.55s cubic-bezier(0.34, 1.3, 0.64, 1);
}

.tour-bosses__card {
  position: relative;
  flex: none;
  width: var(--card-w);
  height: var(--card-h);
  padding: 0;
  border: none;
  border-radius: 20px;
  overflow: hidden;
  background: var(--dark);
  box-shadow: inset 0 0 0 3px transparent;
  text-align: left;
  cursor: pointer;
  opacity: 0.55;
  transform: scale(0.9);
  transition:
    opacity 0.4s,
    transform 0.4s,
    box-shadow 0.4s;

  &--on {
    opacity: 1;
    transform: none;
    box-shadow: inset 0 0 0 3px var(--accent);
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.tour-bosses__glow {
  position: absolute;
  inset: 0;
  background: radial-gradient(circle at 70% 80%, var(--glow), transparent 65%);
}

.tour-bosses__img {
  position: absolute;
  right: -12px;
  bottom: -6px;
  height: 77%;
  object-fit: contain;
  pointer-events: none;
}

.tour-bosses__shadow {
  position: absolute;
  right: 14px;
  bottom: 2px;
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: calc(var(--card-h) * 0.62);
  line-height: 1;
  color: var(--accent);
  text-shadow: 0 0 24px var(--glow);
}

.tour-bosses__text {
  position: absolute;
  left: 11px;
  top: 10px;
  right: 10px;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.tour-bosses__name {
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: 16px;
  line-height: 1.1;
  color: #fff;
}

.tour-bosses__place {
  font-size: 9.5px;
  font-weight: 800;
  letter-spacing: 0.05em;
  color: var(--accent);
}

.tour-bosses__reward {
  position: absolute;
  left: 9px;
  bottom: 9px;
  height: 24px;
  padding: 0 9px;
  border-radius: 999px;
  background: #1b1530;
  color: #fff;
  font-size: 11.5px;
  font-weight: 800;
  display: flex;
  align-items: center;
  gap: 5px;
}

.tour-bosses__nav {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
}

.tour-bosses__arrow {
  width: 34px;
  height: 34px;
  border: none;
  border-radius: 12px;
  background: var(--cm-inset);
  color: var(--cm-ink);
  font-size: 20px;
  font-weight: 800;
  line-height: 1;
  cursor: pointer;

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.tour-bosses__hint {
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-muted);
}

@media (prefers-reduced-motion: reduce) {
  .tour-bosses__track,
  .tour-bosses__card {
    transition: none;
  }
}
</style>
