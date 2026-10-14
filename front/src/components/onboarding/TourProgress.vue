<template>
  <div class="tour-progress">
    <div class="tour-progress__bar">
      <button
        type="button"
        class="tour-progress__btn"
        :style="{ visibility: canBack ? 'visible' : 'hidden' }"
        :aria-label="$t('onboarding.progress.back')"
        data-testid="tour-back"
        @click="emit('back')"
      >
        ←
      </button>
      <div class="tour-progress__segments">
        <button
          v-for="(segment, i) in segments"
          :key="segment.label"
          type="button"
          class="tour-progress__segment"
          :aria-label="segment.label"
          :title="segment.label"
          :aria-current="i === current ? 'step' : undefined"
          :data-testid="`tour-segment-${i}`"
          @click="emit('go', segment.first)"
        >
          <span class="tour-progress__track"
            ><span
              class="tour-progress__fill"
              :class="{ 'tour-progress__fill--done': segment.done }"
              :style="{ width: `${segment.percent}%` }"
          /></span>
        </button>
      </div>
      <button
        type="button"
        class="tour-progress__skip"
        :style="{ visibility: canSkip ? 'visible' : 'hidden' }"
        data-testid="tour-skip"
        @click="emit('skip')"
      >
        {{ $t('onboarding.progress.skip') }}
      </button>
    </div>
    <div class="tour-progress__chip" data-testid="tour-chip">
      <span class="tour-progress__chip-name">{{ chip.name }}</span
      ><span aria-hidden="true">·</span><span>{{ chip.count }}</span>
    </div>
  </div>
</template>

<script setup>
/**
 * The tour's progress (design "Visite guidée"): back, one segment per part (a tap goes to its first
 * screen), skip to the plan, and the chip naming the part.
 */
import { computed } from 'vue'
import { STEPS, chipOf, progressOf, segmentOf } from '@/utils/onboarding'

const props = defineProps({
  step: { type: String, required: true }
})

const emit = defineEmits({
  back: () => true,
  skip: () => true,
  /** @param {number} index */
  go: index => Number.isInteger(index)
})

const segments = computed(() => progressOf(props.step))
const current = computed(() => segmentOf(props.step))
const chip = computed(() => chipOf(props.step))
const canBack = computed(() => STEPS.indexOf(props.step) > 0)
const canSkip = computed(() => props.step !== 'end')
</script>

<style scoped lang="scss">
.tour-progress {
  position: relative;
  z-index: 2;
  width: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 10px 14px 0;
}

.tour-progress__bar {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
}

.tour-progress__btn,
.tour-progress__skip {
  flex: none;
  height: 40px;
  border: none;
  border-radius: 14px;
  font-weight: 800;
  cursor: pointer;
}

.tour-progress__btn {
  width: 40px;
  background: #fff;
  color: #1b1530;
  font-size: 18px;
}

.tour-progress__skip {
  padding: 0 14px;
  background: #1b1530;
  color: #fff;
  font-size: 13px;
}

.tour-progress__segments {
  flex: 1;
  display: flex;
  gap: 5px;
}

.tour-progress__segment {
  flex: 1;
  height: 22px;
  padding: 0;
  border: none;
  background: none;
  cursor: pointer;
  display: flex;
  align-items: center;
}

.tour-progress__track {
  flex: 1;
  height: 7px;
  border-radius: 7px;
  background: rgba(255, 255, 255, 0.35);
  overflow: hidden;
  display: flex;
}

.tour-progress__fill {
  height: 100%;
  border-radius: 7px;
  background: #c6f432;
  transition: width 0.5s cubic-bezier(0.34, 1.3, 0.64, 1);

  &--done {
    background: #1b1530;
  }
}

.tour-progress__chip {
  height: 26px;
  padding: 0 11px;
  border-radius: 999px;
  background: #1b1530;
  color: #fff;
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
}

.tour-progress__chip-name {
  color: #c6f432;
}
</style>
