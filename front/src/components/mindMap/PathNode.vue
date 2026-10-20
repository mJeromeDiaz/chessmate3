<template>
  <div
    class="path-node"
    :class="[
      `path-node--${step.status}`,
      `path-node--${step.kind}`,
      { 'path-node--current': current, 'path-node--ringed': ringed }
    ]"
    :style="{ transform: `translateX(${offset * 64}px)` }"
  >
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

      <span v-if="step.status === 'hidden'" class="path-node__glyph">?</span>
      <BossPortrait
        v-else-if="boss"
        class="path-node__boss"
        :character="step.character"
        :pose="step.status === 'done' ? 'beaten' : 'angry'"
        round
      />
      <q-icon
        v-else-if="step.kind === 'chest'"
        :name="step.status === 'done' ? 'redeem' : 'card_giftcard'"
        class="path-node__icon"
      />
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
    <div class="path-node__label">
      <span v-if="step.number" class="path-node__number">{{
        step.number
      }}</span>
      <span class="path-node__title">{{ title }}</span>
      <span
        v-if="step.status === 'soon'"
        class="path-node__tag"
        data-testid="path-step-soon"
        >{{ $t('mindMapPath.status.soon') }}</span
      >
    </div>
  </div>
</template>

<script setup>
/**
 * A step on the path (docs/MIND_MAP.md): a round button, by kind (an exercise and the ring of its
 * passes, a chest, a boss's face) and by status (locked, open, soon, hidden « ? », done, skipped).
 * The current one pulses.
 */
import { computed } from 'vue'
import BossPortrait from '@/components/mindMap/BossPortrait.vue'
import { bossName, isBoss, stepTitle } from '@/utils/mindMapPath'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').PathStep>} */
  step: { type: Object, required: true },
  /** The API's capacity (its texts). */
  capacity: { type: String, required: true },
  /** Where the path winds (-1 to 1). */
  offset: { type: Number, default: 0 },
  /** The step to play next. */
  current: { type: Boolean, default: false }
})

const emit = defineEmits({ select: null })

const boss = computed(() => isBoss(props.step.kind))
/** An exercise of several passes: its ring around it. */
const ringed = computed(
  () => props.step.kind === 'exercise' && props.step.required > 1
)

const title = computed(() =>
  props.step.status === 'hidden'
    ? t('mindMapPath.kinds.hidden_boss')
    : boss.value
      ? bossName(props.step)
      : stepTitle(props.capacity, props.step.key)
)

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
.path-node {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  width: 140px;
  transition: transform 0.2s;
}

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

.path-node--boss .path-node__button,
.path-node--mini_boss .path-node__button,
.path-node--hidden_boss .path-node__button {
  width: 92px;
  height: 92px;
  padding: 6px;
}

.path-node--boss .path-node__button {
  width: 108px;
  height: 108px;
}

.path-node--locked .path-node__button,
.path-node--hidden .path-node__button {
  background: var(--cm-subtle);
  color: var(--cm-faint);
  box-shadow: 0 6px 0 var(--cm-dash);
}

.path-node--locked .path-node__boss {
  filter: grayscale(1);
  opacity: 0.55;
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

.path-node--chest.path-node--done .path-node__button,
.path-node--chest.path-node--open .path-node__button {
  background: #ffd43b;
  color: #1b1530;
  box-shadow: 0 6px 0 #c99a00;
}

.path-node--current .path-node__button {
  animation: path-node-pulse 1.6s ease-in-out infinite;
}

@keyframes path-node-pulse {
  0%,
  100% {
    filter: drop-shadow(0 0 0 transparent);
  }
  50% {
    filter: drop-shadow(0 0 12px var(--capacity, var(--cm-brand)));
  }
}

@media (prefers-reduced-motion: reduce) {
  .path-node--current .path-node__button {
    animation: none;
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
  font-size: 28px;
  font-weight: 800;
}

.path-node__icon {
  font-size: 32px;
}

.path-node__boss {
  width: 100%;
}

.path-node--ringed .path-node__label {
  margin-top: 12px;
}

.path-node__label {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
  max-width: 140px;
  text-align: center;
  line-height: 1.2;
}

.path-node__number {
  font-size: 11px;
  font-weight: 800;
  color: var(--cm-muted);
}

.path-node__title {
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-ink);
}

.path-node--locked .path-node__title,
.path-node--hidden .path-node__title {
  color: var(--cm-faint);
}

.path-node__tag {
  padding: 1px 8px;
  border-radius: 999px;
  background: var(--cm-subtle);
  color: var(--cm-muted);
  font-size: 11px;
  font-weight: 800;
}
</style>
