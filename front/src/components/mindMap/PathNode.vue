<template>
  <div
    class="path-node"
    :class="[
      `path-node--${step.status}`,
      `path-node--${step.kind}`,
      {
        'path-node--current': current,
        'path-node--ringed': ringed,
        'path-node--boss-like': boss,
        'path-node--chosen': chosen
      }
    ]"
  >
    <div class="path-node__spot" :style="{ '--x': offset }">
      <span v-if="current" class="path-node__bubble" aria-hidden="true">{{
        boss ? $t('mindMapPath.boss.fight') : $t('mindMapPath.sheet.start')
      }}</span>

      <template v-if="boss">
        <button
          type="button"
          class="path-node__boss-button"
          :aria-label="ariaLabel"
          :data-testid="`path-step-${step.key}`"
          :data-status="step.status"
          @click="emit('select', step)"
        >
          <span v-if="current" class="path-node__halo" aria-hidden="true" />
          <span class="path-node__disc">
            <span v-if="step.status === 'hidden'" class="path-node__mystery"
              >?</span
            >
            <BossPortrait
              v-else
              class="path-node__face"
              :character="step.character"
              :mood="pathMood(step)"
              :pose="step.status === 'done' ? 'beaten' : 'idle'"
              round
            />
          </span>
          <span
            v-if="step.status === 'done'"
            class="path-node__beaten"
            aria-hidden="true"
            ><q-icon name="check"
          /></span>
        </button>
        <span class="path-node__tag">{{ tag }}</span>
        <span class="path-node__boss-name">{{ title }}</span>
        <span
          v-if="step.status === 'soon'"
          class="path-node__soon"
          data-testid="path-step-soon"
          >{{ $t('mindMapPath.status.soon') }}</span
        >
      </template>

      <template v-else>
        <button
          type="button"
          class="path-node__button"
          :aria-label="ariaLabel"
          :data-testid="`path-step-${step.key}`"
          :data-status="step.status"
          @click="emit('select', step)"
        >
          <svg
            v-if="ringed"
            class="path-node__ring"
            viewBox="0 0 100 100"
            aria-hidden="true"
          >
            <circle
              v-for="(arc, i) in arcs"
              :key="i"
              cx="50"
              cy="50"
              r="45"
              pathLength="100"
              :stroke-dasharray="`${arc.length} 100`"
              :stroke-dashoffset="-arc.start"
              :class="{ 'path-node__arc--done': arc.done }"
              class="path-node__arc"
            />
          </svg>
          <WoodChest v-if="step.kind === 'chest'" :state="chestState" />
          <q-icon
            v-else-if="step.status === 'done'"
            name="check"
            class="path-node__icon"
          />
          <q-icon
            v-else-if="step.status === 'skipped'"
            name="fast_forward"
            class="path-node__icon"
          />
          <q-icon
            v-else-if="step.status === 'locked'"
            name="lock"
            class="path-node__icon"
          />
          <span v-else class="path-node__glyph">{{ glyph }}</span>
        </button>
        <button
          type="button"
          tabindex="-1"
          class="path-node__label"
          :class="
            offset > 0 ? 'path-node__label--left' : 'path-node__label--right'
          "
          @click="emit('select', step)"
        >
          <span class="path-node__kicker">{{ kicker }}</span>
          <span class="path-node__title">{{ title }}</span>
          <span
            v-if="step.status === 'soon'"
            class="path-node__soon"
            data-testid="path-step-soon"
            >{{ $t('mindMapPath.status.soon') }}</span
          >
        </button>
      </template>
    </div>
  </div>
</template>

<script setup>
/**
 * A step on the path (design « Parcours zone »), on its own row, winding by its place in its
 * section: an exercise (a round button, the ring of its passes, its number, its title on the
 * side), a chest (a wooden chest), a boss (a large disc with its face, its tag and name below, in
 * the middle). By status: locked, open, soon, hidden « ? », done, skipped. The next one floats a
 * « Commencer » above it; the one shown in the detail is ringed.
 */
import { computed } from 'vue'
import BossPortrait from '@/components/mindMap/BossPortrait.vue'
import WoodChest from '@/components/mindMap/WoodChest.vue'
import { bossName, isBoss, pathMood, stepTitle } from '@/utils/mindMapPath'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathStep>} */
  step: { type: Object, required: true },
  /** The API's capacity (its texts). */
  capacity: { type: String, required: true },
  /** Its offset from the middle, in pixels (the page scales it down on a phone). */
  offset: { type: Number, default: 0 },
  /** The step to play next. */
  current: { type: Boolean, default: false },
  /** The step shown in the detail. */
  chosen: { type: Boolean, default: false }
})

const emit = defineEmits({ select: null })

const boss = computed(() => isBoss(props.step.kind))
/** An exercise of several passes: its ring around it. */
const ringed = computed(
  () => props.step.kind === 'exercise' && props.step.required > 1
)

const title = computed(() => {
  if (props.step.status === 'hidden') return t('mindMapPath.kinds.hidden_boss')
  if (boss.value) return bossName(props.step)
  if (props.step.kind === 'chest')
    return props.step.status === 'done'
      ? t('mindMapPath.page.chestOpened')
      : t('mindMapPath.sheet.crystals', { n: props.step.crystals })
  return stepTitle(props.capacity, props.step.key)
})

const kicker = computed(() => {
  if (props.step.kind === 'chest') return t('mindMapPath.kinds.chest')
  return props.step.number
    ? t('mindMapPath.page.step', { n: props.step.number })
    : t(`mindMapPath.kinds.${props.step.kind}`)
})

const tag = computed(() =>
  props.step.kind === 'boss'
    ? t('mindMapPath.page.bossOfChapter')
    : t(`mindMapPath.kinds.${props.step.kind}`)
)

/** Earned and not opened yet: ready; earned and opened: open; else locked. */
const chestState = computed(() => {
  if (props.step.status !== 'done') return 'locked'
  return props.step.chest && !props.step.chest.opened ? 'ready' : 'opened'
})

/** An exercise's last digit (« 2.1.3 » → 3). */
const glyph = computed(() => props.step.number?.split('.').pop() ?? '')

/**
 * The ring: one arc per pass, those done lit. The gap leaves room for the arcs' round caps (about
 * 2 units of the path's 100).
 */
const arcs = computed(() => {
  const n = props.step.required
  const gap = 9
  return Array.from({ length: n }, (_, i) => ({
    start: (100 / n) * i + gap / 2,
    length: 100 / n - gap,
    done: i < props.step.passes || props.step.status === 'done'
  }))
})

const ariaLabel = computed(
  () => `${title.value} · ${t(`mindMapPath.status.${props.step.status}`)}`
)
</script>

<style scoped lang="scss">
// One row per step, as in the design: its height by kind.
.path-node {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100px;

  &--chest {
    height: 92px;
  }

  &--mini_boss,
  &--hidden_boss {
    height: 184px;
  }

  &--boss {
    height: 250px;
  }
}

// The offset scaled down on a phone (labels beside a narrow path).
.path-node__spot {
  --wind: 0.55;
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  transform: translateX(calc(var(--x) * var(--wind) * 1px));

  @media (min-width: 560px) {
    --wind: 1;
  }
}

.path-node__bubble {
  position: absolute;
  left: 50%;
  bottom: calc(100% + 10px);
  z-index: 1;
  padding: 7px 12px;
  border-radius: 12px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  box-shadow:
    0 4px 0 var(--cm-dash),
    0 0 0 2px var(--cm-line);
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  white-space: nowrap;
  pointer-events: none;
  transform: translateX(-50%);
  animation: path-node-float 1.8s ease-in-out infinite;
}

@keyframes path-node-float {
  0%,
  100% {
    transform: translate(-50%, 0);
  }
  50% {
    transform: translate(-50%, -6px);
  }
}

// The exercise's button.
.path-node__button {
  position: relative;
  display: grid;
  place-items: center;
  width: 72px;
  height: 72px;
  padding: 0;
  border: none;
  border-radius: 50%;
  background: var(--capacity, var(--cm-brand));
  color: var(--capacity-ink, #fff);
  box-shadow: 0 6px 0 var(--capacity-deep, var(--cm-brand-deep));
  cursor: pointer;
  transition:
    transform 0.12s,
    box-shadow 0.12s;

  &:active {
    transform: translateY(4px);
    box-shadow: 0 2px 0 var(--capacity-deep, var(--cm-brand-deep));
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 4px;
  }
}

.path-node--current .path-node__button {
  box-shadow:
    0 6px 0 var(--capacity-deep, var(--cm-brand-deep)),
    0 0 0 6px var(--cm-page),
    0 0 0 10px var(--capacity, var(--cm-brand));
}

// With its ring of passes, the ring is the mark: the button glows instead (no ring over it).
.path-node--current.path-node--ringed .path-node__button {
  box-shadow: 0 6px 0 var(--capacity-deep, var(--cm-brand-deep));
  animation: path-node-glow 1.6s ease-in-out infinite;
}

@keyframes path-node-glow {
  0%,
  100% {
    filter: drop-shadow(0 0 0 transparent);
  }
  50% {
    filter: drop-shadow(0 0 12px var(--capacity, var(--cm-brand)));
  }
}

.path-node--locked .path-node__button {
  background: var(--cm-subtle);
  color: var(--cm-faint);
  box-shadow: 0 6px 0 var(--cm-dash);
}

.path-node--soon .path-node__button {
  background: var(--cm-surface);
  color: var(--cm-muted);
  box-shadow:
    inset 0 0 0 3px var(--cm-dash),
    0 6px 0 var(--cm-dash);
}

.path-node--skipped .path-node__button {
  background: var(--cm-surface);
  color: var(--capacity-deep, var(--cm-brand));
  box-shadow:
    inset 0 0 0 3px var(--capacity, var(--cm-brand)),
    0 6px 0 var(--capacity-deep, var(--cm-brand-deep));
}

// A chest: the wooden chest itself, no disc.
.path-node--chest .path-node__button {
  width: 80px;
  background: none;
  box-shadow: none;

  &:active {
    transform: translateY(2px);
    box-shadow: none;
  }
}

.path-node__ring {
  // Around the button's face, clear of it and of its 3D shadow (6 px below).
  position: absolute;
  inset: -16px;
  width: calc(100% + 32px);
  height: calc(100% + 32px);
  // Centred on what the eye sees: the face and its 6 px shadow below, 3 px lower than the face.
  transform: translateY(3px) rotate(-90deg);
  pointer-events: none;
}

.path-node__arc {
  fill: none;
  // A pass to come: the capacity's colour, faint (readable on the light and the dark page).
  stroke: color-mix(in srgb, var(--capacity, var(--cm-brand)) 28%, transparent);
  stroke-width: 7;
  stroke-linecap: round;

  &--done {
    stroke: var(--capacity, var(--cm-brand));
  }
}

.path-node__glyph {
  font-family: var(--cm-heading);
  font-size: 24px;
  font-weight: 800;
}

.path-node__icon {
  font-size: 30px;
}

// The title beside the button, away from the side the path leans to.
.path-node__label {
  position: absolute;
  top: 50%;
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 120px;
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  text-align: left;
  cursor: pointer;
  transform: translateY(-50%);

  @media (min-width: 560px) {
    width: 210px;
  }

  &--right {
    left: calc(100% + 16px);
  }

  &--left {
    right: calc(100% + 16px);
    align-items: flex-end;
    text-align: right;
  }
}

.path-node--ringed .path-node__label--right {
  left: calc(100% + 24px);
}

.path-node--ringed .path-node__label--left {
  right: calc(100% + 24px);
}

.path-node__kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.05em;
  text-transform: uppercase;
  color: var(--capacity-deep, var(--cm-brand));
}

.path-node--chest .path-node__kicker {
  color: #c99a00;
}

.path-node__title {
  font-size: 14px;
  font-weight: 700;
  line-height: 1.3;
  color: var(--cm-ink);
  text-wrap: pretty;
}

.path-node--locked .path-node__kicker,
.path-node--hidden .path-node__kicker {
  color: var(--cm-faint);
}

.path-node--locked .path-node__title,
.path-node--hidden .path-node__title {
  color: var(--cm-muted);
}

.path-node__soon {
  align-self: flex-start;
  margin-top: 2px;
  padding: 1px 8px;
  border-radius: 999px;
  background: var(--cm-subtle);
  color: var(--cm-muted);
  font-size: 11px;
  font-weight: 800;
}

.path-node--boss-like .path-node__soon {
  align-self: center;
}

// A boss: a large disc, its face inside, in the middle of the path.
.path-node__boss-button {
  position: relative;
  width: 92px;
  height: 92px;
  padding: 0;
  border: none;
  background: none;
  cursor: pointer;

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 6px;
    border-radius: 50%;
  }
}

.path-node--boss .path-node__boss-button {
  width: 150px;
  height: 150px;
}

.path-node__halo {
  position: absolute;
  inset: -14px;
  border-radius: 50%;
  background: radial-gradient(
    closest-side,
    var(--capacity, var(--cm-brand)),
    transparent
  );
  animation: path-node-pulse 2s ease-in-out infinite;
}

@keyframes path-node-pulse {
  0%,
  100% {
    opacity: 0.35;
    transform: scale(1);
  }
  50% {
    opacity: 0.8;
    transform: scale(1.08);
  }
}

.path-node__disc {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  overflow: hidden;
  border-radius: 50%;
  background: radial-gradient(
    circle at 50% 35%,
    color-mix(in srgb, var(--capacity, var(--cm-brand)) 40%, transparent),
    #1b1530 72%
  );
  box-shadow: 0 0 0 4px var(--cm-line);
}

.path-node--open .path-node__disc {
  box-shadow:
    0 0 0 5px var(--cm-page),
    0 0 0 10px var(--capacity, var(--cm-brand));
}

.path-node--done .path-node__disc {
  box-shadow: 0 0 0 5px var(--capacity, var(--cm-brand));
}

.path-node--locked .path-node__disc,
.path-node--hidden .path-node__disc,
.path-node--soon .path-node__disc {
  background: radial-gradient(circle at 50% 35%, #2b2640, #1b1530 72%);
}

.path-node__face {
  width: 90%;
}

.path-node--locked .path-node__face,
.path-node--soon .path-node__face {
  filter: grayscale(1) brightness(0.45);
}

.path-node__mystery {
  align-self: center;
  font-family: var(--cm-heading);
  font-size: 40px;
  font-weight: 800;
  color: #4a4460;
}

.path-node__beaten {
  position: absolute;
  right: 2px;
  bottom: 2px;
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: var(--capacity, var(--cm-brand));
  color: var(--capacity-ink, #fff);
  box-shadow: 0 0 0 4px var(--cm-page);
  font-size: 20px;
}

.path-node__tag {
  margin-top: 12px;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--capacity, var(--cm-brand));
  color: var(--capacity-ink, #fff);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.07em;
  text-transform: uppercase;
}

.path-node--boss .path-node__tag {
  background: #ffd43b;
  color: #1b1530;
}

.path-node--locked .path-node__tag,
.path-node--hidden .path-node__tag,
.path-node--soon .path-node__tag {
  background: var(--cm-subtle);
  color: var(--cm-muted);
}

.path-node__boss-name {
  margin-top: 5px;
  font-family: var(--cm-heading);
  font-size: 19px;
  font-weight: 800;
  color: var(--cm-ink);
  white-space: nowrap;
}

.path-node--locked .path-node__boss-name,
.path-node--hidden .path-node__boss-name {
  color: var(--cm-muted);
}

@media (prefers-reduced-motion: reduce) {
  .path-node__bubble,
  .path-node__halo,
  .path-node--current.path-node--ringed .path-node__button {
    animation: none;
  }
}
</style>
