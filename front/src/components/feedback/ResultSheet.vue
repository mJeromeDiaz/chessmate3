<template>
  <div class="result-sheet-wrap">
    <div
      class="result-sheet"
      :class="[`result-sheet--${kind}`, { 'result-sheet--in': step >= 1 }]"
      :style="{
        '--sheet-bg': theme.sheetBg,
        '--sheet-accent': theme.accent
      }"
      data-testid="result-sheet"
      :data-kind="kind"
    >
      <template v-if="win">
        <div
          v-for="(b, i) in BUMPS"
          :key="`o${i}`"
          class="result-sheet__bump"
          :style="{
            left: `${b[0]}%`,
            top: `${14 - b[1] / 2}px`,
            width: `${b[1]}px`,
            height: `${b[1]}px`,
            background: theme.accent,
            transform: `scale(${step >= 2 ? 1 : 0.2})`,
            transitionDelay: `${i * 55}ms`
          }"
        />
      </template>
      <div class="result-sheet__base" />
      <template v-if="win">
        <div
          v-for="(b, i) in BUMPS"
          :key="`i${i}`"
          class="result-sheet__bump"
          :style="{
            left: `calc(${b[0]}% + 6px)`,
            top: `${20 - b[1] / 2}px`,
            width: `${b[1] - 12}px`,
            height: `${b[1] - 12}px`,
            background: theme.sheetBg,
            transform: `scale(${step >= 2 ? 1 : 0.2})`,
            transitionDelay: `${i * 55}ms`
          }"
        />
      </template>
      <div v-else class="result-sheet__tiles" aria-hidden="true">
        <div
          v-for="(t, i) in tiles"
          :key="i"
          class="result-sheet__tile"
          :style="t"
        />
      </div>

      <div class="result-sheet__body">
        <div
          class="result-sheet__head"
          :style="{ transform: `translateX(${shakeX}px)` }"
        >
          <div v-if="win" class="result-sheet__promo" aria-hidden="true">
            <div
              class="result-sheet__promo-square"
              :style="{
                background: `repeating-linear-gradient(to bottom, ${theme.stripeA} 0 16px, ${theme.stripeB} 16px 32px)`
              }"
            >
              <div
                class="result-sheet__glyph result-sheet__pawn"
                :style="{
                  transform: `translateY(${step >= 2 ? 0 : 48}px)`,
                  opacity: step >= 3 ? 0 : 1
                }"
                >♟︎</div
              >
              <div
                class="result-sheet__glyph result-sheet__promoted"
                :style="{
                  color: theme.promoInk,
                  transform: `scale(${step >= 3 ? 1 : 0})`
                }"
                >{{ kind === 'help' ? '♞︎' : '♛︎' }}</div
              >
              <div
                class="result-sheet__flash"
                :style="{
                  background: theme.accent,
                  opacity: step === 3 ? 0.9 : 0
                }"
              />
            </div>
          </div>
          <div v-else class="result-sheet__king" aria-hidden="true">
            <div class="result-sheet__king-square" />
            <div
              class="result-sheet__glyph result-sheet__king-glyph"
              :style="{ transform: `rotate(${kingR}deg)` }"
              >♚︎</div
            >
            <div
              class="result-sheet__badge"
              :style="{
                transform: `scale(${step >= 4 ? 1 : 0}) rotate(-12deg)`
              }"
              >{{ badge }}</div
            >
          </div>

          <div
            class="result-sheet__titles"
            :style="{
              transform: `scale(${step >= 2 ? 1 : 0.5})`,
              opacity: step >= 2 ? 1 : 0
            }"
            role="status"
          >
            <div
              class="result-sheet__title cm-heading"
              :style="{ color: theme.titleInk }"
              data-testid="result-title"
              >{{ title }}</div
            >
            <div
              class="result-sheet__sub"
              :style="{ color: theme.subInk }"
              data-testid="result-sub"
              >{{ sub }}</div
            >
          </div>

          <span
            v-if="xpLabel"
            class="result-sheet__xp"
            :style="{
              color: theme.titleInk,
              transform: `scale(${step >= 3 ? 1 : 0})`
            }"
            ><span data-testid="result-xp">{{ xpLabel }}</span
            ><template v-if="win"
              ><span
                class="result-sheet__spark result-sheet__spark--big"
                :style="{
                  color: theme.sparkInk,
                  transform: `scale(${step >= 4 ? 1 : 0}) rotate(20deg)`
                }"
                aria-hidden="true"
                >✦</span
              ><span
                class="result-sheet__spark result-sheet__spark--small"
                :style="{
                  color: theme.sparkInk,
                  transform: `scale(${step >= 4 ? 1 : 0})`
                }"
                aria-hidden="true"
                >✦</span
              ></template
            ></span
          >
        </div>

        <div
          v-if="actions.length"
          class="result-sheet__actions"
          :class="{ 'result-sheet__actions--in': buttonsIn }"
        >
          <button
            v-for="action in actions"
            :key="action.label"
            type="button"
            class="result-sheet__btn"
            :class="{ 'result-sheet__btn--primary': action.primary }"
            :style="
              action.primary
                ? {
                    background: theme.btnBg,
                    boxShadow: `0 4px 0 ${theme.btnShadow}`
                  }
                : { boxShadow: `0 4px 0 ${theme.accent}` }
            "
            :disabled="action.disable || action.loading"
            :data-testid="action.testid"
            @click="action.onClick()"
          >
            <q-spinner v-if="action.loading" size="1.2em" />
            <template v-else>{{ action.label }}</template>
          </button>
        </div>
        <slot />
      </div>
    </div>

    <div v-if="confetti && win && step >= 2" class="result-sheet__confetti">
      <ConfettiBurst
        :key="run"
        :colors="theme.confetti"
        :count="kind === 'win' ? 70 : 30"
        :pop="false"
        pieces
      />
    </div>
  </div>
</template>

<script setup>
/**
 * The verdict of an exercise sliding up (design "Animation Puzzle"): green for a success (a pawn
 * promoting to a queen, confetti), yellow for a success with help (a knight), pink for a miss
 * (the king wobbling, a "?" badge, the sheet shaking). Driven by `useFeedbackTimeline`'s beats.
 * In the right-hand panel on a wide screen, at the bottom of the screen on a phone.
 *
 * @typedef {object} SheetAction
 * @property {string} label
 * @property {() => void} onClick
 * @property {boolean} [primary]
 * @property {boolean} [disable]
 * @property {boolean} [loading]
 * @property {string} [testid]
 */
import { computed } from 'vue'
import ConfettiBurst from '@/components/training/ConfettiBurst.vue'
import { FEEDBACK_THEMES, xpText } from '@/utils/feedback'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/feedback').FeedbackKind>} */
  kind: { type: String, required: true },
  /** The timeline's beat. */
  step: { type: Number, required: true },
  /** The timeline's play counter: replays the confetti. */
  run: { type: Number, default: 0 },
  title: { type: String, required: true },
  sub: { type: String, default: '' },
  /** The submission's `xp`; undefined while it has not answered, null: no chip. */
  xp: { type: [Number, null], default: undefined },
  /** @type {import('vue').PropType<SheetAction[]>} */
  actions: { type: Array, default: () => [] },
  confetti: { type: Boolean, default: true },
  /**
   * @type {import('vue').PropType<import('@/utils/feedback').FeedbackKind|null>} another verdict's
   * colours on this one's shape (the evaluation's "Presque !": a miss in yellow)
   */
  palette: { type: String, default: null },
  /** The badge on the king of a miss. */
  badge: { type: String, default: '?' }
})

/** The scallops on top of a success: [left %, size px]. */
const BUMPS = [
  [-8, 120],
  [16, 92],
  [34, 132],
  [58, 98],
  [76, 126],
  [96, 90]
]
const SHAKE = { 2: -10, 3: 9, 4: -6, 5: 3 }
const KING = { 2: -32, 3: -32, 4: 14, 5: -6 }

const win = computed(() => props.kind !== 'miss')
const theme = computed(() => FEEDBACK_THEMES[props.palette ?? props.kind])
const xpLabel = computed(() => xpText(props.xp))
const shakeX = computed(() => (win.value ? 0 : (SHAKE[props.step] ?? 0)))
const kingR = computed(() => KING[props.step] ?? 0)
const buttonsIn = computed(() => props.step >= (win.value ? 3 : 5))

/** A miss: two rows of squares falling onto the sheet, a few left crooked or missing. */
const tiles = computed(() =>
  Array.from({ length: 24 }, (_, k) => {
    const r = k >= 12 ? 1 : 0
    const c = k % 12
    const gap = r === 0 && (c * 7 + 3) % 5 < 2
    const crook = r === 0 ? (c % 4 === 1 ? 9 : c % 5 === 3 ? -7 : 0) : 0
    const landed = props.step >= 2
    return {
      background: gap
        ? 'transparent'
        : (r + c) % 2
          ? theme.value.sheetBg
          : theme.value.accent,
      opacity: props.step >= 1 ? 1 : 0,
      transform: `translateY(${landed ? (crook ? 3 : 0) : -60 - c * 6}px) rotate(${landed ? crook : c % 2 ? 25 : -25}deg)`,
      transitionDelay: `${c * 22 + (1 - r) * 40}ms`
    }
  })
)
</script>

<style scoped lang="scss">
.result-sheet {
  position: relative;
  min-height: 196px;
  margin-top: 36px;
  overflow-x: clip;
  overflow-y: visible;
  opacity: 0;
  transform: translateY(24px);
  transition:
    transform 0.55s cubic-bezier(0.34, 1.35, 0.64, 1),
    opacity 0.25s;
  color: #1b1530;

  &--in {
    opacity: 1;
    transform: none;
  }
}

.result-sheet__base {
  position: absolute;
  inset: 0;
  background: var(--sheet-bg);
  border-radius: 20px;

  .result-sheet--win &,
  .result-sheet--help & {
    border-top: 6px solid var(--sheet-accent);
  }
}

.result-sheet__bump {
  position: absolute;
  border-radius: 50%;
  transition: transform 0.5s cubic-bezier(0.34, 1.9, 0.64, 1);
}

.result-sheet__tiles {
  position: absolute;
  left: 0;
  right: 0;
  top: -44px;
  height: 44px;
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  grid-template-rows: 22px 22px;
}

.result-sheet__tile {
  transition:
    transform 0.45s cubic-bezier(0.34, 1.7, 0.64, 1),
    opacity 0.2s;
}

.result-sheet__body {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 36px 20px 24px;
}

.result-sheet__head {
  display: flex;
  align-items: center;
  gap: 12px;
  transition: transform 0.09s ease-out;
}

.result-sheet__glyph {
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  line-height: 1;
}

.result-sheet__promo {
  flex: none;
}

.result-sheet__promo-square {
  position: relative;
  width: 52px;
  height: 64px;
  border-radius: 14px;
  overflow: hidden;
}

.result-sheet__pawn,
.result-sheet__promoted {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: flex-start;
  justify-content: center;
}

.result-sheet__pawn {
  padding-top: 6px;
  font-size: 30px;
  color: #fff;
  transition:
    transform 0.5s cubic-bezier(0.5, 0, 0.2, 1),
    opacity 0.15s;
}

.result-sheet__promoted {
  padding-top: 4px;
  font-size: 34px;
  transition: transform 0.5s cubic-bezier(0.34, 2, 0.64, 1);
}

.result-sheet__flash {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  height: 16px;
  transition: opacity 0.5s;
}

.result-sheet__king {
  position: relative;
  flex: none;
  width: 52px;
  height: 64px;
}

.result-sheet__king-square {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 52px;
  border-radius: 14px;
  background: var(--sheet-accent);
}

.result-sheet__king-glyph {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 6px;
  display: flex;
  justify-content: center;
  font-size: 38px;
  color: #1b1530;
  transform-origin: 50% 100%;
  transition: transform 0.16s ease-out;
}

.result-sheet__badge {
  position: absolute;
  right: -8px;
  top: -2px;
  min-width: 28px;
  height: 28px;
  padding: 0 6px;
  box-sizing: border-box;
  border-radius: 999px;
  background: var(--sheet-accent);
  border: 2.5px solid var(--sheet-bg);
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 14px;
  transition: transform 0.4s cubic-bezier(0.34, 2.2, 0.64, 1);
}

.result-sheet__titles {
  flex: 1;
  min-width: 0;
  transform-origin: left center;
  transition:
    transform 0.5s cubic-bezier(0.34, 1.8, 0.64, 1),
    opacity 0.2s;
}

.result-sheet__title {
  font-weight: 800;
  font-size: 30px;
  line-height: 1.05;
  overflow-wrap: anywhere;
}

.result-sheet__sub {
  margin-top: 4px;
  font-size: 13.5px;
  font-weight: 700;
}

.result-sheet__xp {
  position: relative;
  flex: none;
  padding: 7px 12px;
  border-radius: 999px;
  background: #fff;
  font-weight: 800;
  font-size: 14px;
  transition: transform 0.45s cubic-bezier(0.34, 2, 0.64, 1);
}

.result-sheet__spark {
  position: absolute;
  transition: transform 0.4s cubic-bezier(0.34, 2, 0.64, 1);

  &--big {
    right: -6px;
    top: -14px;
    font-size: 16px;
  }

  &--small {
    left: -12px;
    top: -6px;
    font-size: 11px;
    transition-delay: 0.1s;
  }
}

.result-sheet__actions {
  display: flex;
  gap: 8px;
  opacity: 0;
  transform: translateY(16px);
  transition:
    opacity 0.3s,
    transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);

  &--in {
    opacity: 1;
    transform: none;
  }
}

.result-sheet__btn {
  flex: 1;
  min-height: 54px;
  padding: 0 12px;
  border: none;
  border-radius: 16px;
  background: #fff;
  color: #1b1530;
  font-family: inherit;
  font-weight: 800;
  font-size: 15px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  cursor: pointer;

  &--primary {
    flex: 1.4;
    color: #fff;
  }

  &:disabled {
    opacity: 0.6;
    cursor: default;
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.result-sheet__confetti {
  position: fixed;
  inset: 0;
  pointer-events: none;
  z-index: 2100;
}

// On a phone, the sheet comes up from the bottom of the screen, over the buttons.
@media (max-width: 1023px) {
  .result-sheet {
    position: fixed;
    left: 0;
    right: 0;
    bottom: 0;
    margin: 0;
    z-index: 2050;
    opacity: 1;
    transform: translateY(115%);
    padding-bottom: env(safe-area-inset-bottom);

    &--in {
      transform: none;
    }
  }

  .result-sheet__base {
    border-radius: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .result-sheet,
  .result-sheet * {
    transition: none !important;
  }
}
</style>
