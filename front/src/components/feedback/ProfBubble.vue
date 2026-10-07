<template>
  <div class="prof-bubble" data-testid="prof-bubble" :data-kind="kind ?? ''">
    <div class="prof-bubble__prof" :style="{ transform: pose }">
      <ProfAvatar
        class="prof-bubble__avatar"
        :image="prof.image"
        :bg="prof.bg"
        :deep="prof.deep"
        :ink="prof.ink"
        :glyph="prof.glyph"
        radius="22px"
      />
    </div>
    <div class="prof-bubble__bubble" :style="{ borderColor: border }">
      <div class="prof-bubble__kicker" :style="{ color: prof.accentInk }"
        >{{ prof.name.toUpperCase() }} · {{ kicker }}</div
      >
      <div class="prof-bubble__text" :data-testid="textTestid">{{ text }}</div>
    </div>
  </div>
</template>

<script setup>
/**
 * The module's professor talking above the game (design "Animation Puzzle"): the instruction,
 * the hint, then their reaction to the verdict, jumping for joy or shaking their head in step
 * with the result sheet (`useFeedbackTimeline`).
 */
import { computed } from 'vue'
import ProfAvatar from '@/components/session/ProfAvatar.vue'
import { FEEDBACK_THEMES } from '@/utils/feedback'

const props = defineProps({
  /** @type {import('vue').PropType<ReturnType<typeof import('@/utils/feedback').feedbackProf>>} */
  prof: { type: Object, required: true },
  kicker: { type: String, required: true },
  text: { type: String, required: true },
  /** @type {import('vue').PropType<import('@/utils/feedback').FeedbackKind|null>} the verdict, once known */
  kind: { type: String, default: null },
  /** The timeline's beat (0: no verdict shown yet). */
  step: { type: Number, default: 0 },
  /** A hint is shown: yellow border. */
  hint: { type: Boolean, default: false },
  /** Kept from the status line it replaces, for the tests. */
  textTestid: { type: String, default: undefined }
})

/** Tilt of the professor at each beat: a jump on a success, a head shake on a miss. */
const WIN_POSES = { 2: [-14, -6], 3: [0, 4] }
const MISS_POSES = { 2: [0, -8], 3: [0, 8], 4: [0, -5] }

const pose = computed(() => {
  if (!props.kind) return 'none'
  const poses = props.kind === 'miss' ? MISS_POSES : WIN_POSES
  const [y, r] = poses[props.step] ?? [0, 0]
  return `translateY(${y}px) rotate(${r}deg)`
})

const border = computed(() => {
  if (props.kind && props.step >= 2) return FEEDBACK_THEMES[props.kind].accent
  return props.hint ? '#FFD43B' : 'var(--cm-line)'
})
</script>

<style scoped lang="scss">
.prof-bubble {
  display: flex;
  gap: 12px;
  align-items: center;
  min-height: 80px;
}

.prof-bubble__prof {
  flex: none;
  transition: transform 0.35s cubic-bezier(0.34, 1.8, 0.64, 1);
}

.prof-bubble__avatar {
  width: 80px;
  height: 80px;
}

.prof-bubble__bubble {
  flex: 1;
  min-width: 0;
  padding: 10px 14px;
  background: var(--cm-surface);
  border: 2px solid;
  border-radius: 20px 20px 20px 6px;
  transition: border-color 0.3s;
}

.prof-bubble__kicker {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.02em;
}

.prof-bubble__text {
  font-size: 15px;
  line-height: 1.4;
  text-wrap: pretty;
}

@media (prefers-reduced-motion: reduce) {
  .prof-bubble__prof,
  .prof-bubble__bubble {
    transition: none;
  }
}
</style>
