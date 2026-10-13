<template>
  <div class="tour-end">
    <div
      class="tour-end__choices"
      role="radiogroup"
      aria-label="Par où commencer"
      data-testid="tour-end-choices"
    >
      <button
        v-for="(choice, i) in END_CHOICES"
        :key="choice.id"
        type="button"
        role="radio"
        class="tour-choice tour-end__choice tour-in"
        :class="{ 'tour-choice--on': modelValue === choice.id }"
        :aria-checked="modelValue === choice.id"
        :style="{ animationDelay: `${0.15 + i * 0.08}s` }"
        :data-testid="`tour-end-${choice.id}`"
        @click="emit('update:modelValue', choice.id)"
      >
        <span class="tour-end__glyph" aria-hidden="true">{{
          choice.glyph
        }}</span>
        <span class="tour-end__text">
          <span class="tour-end__title"
            >{{ choice.title
            }}<span v-if="tagOf(choice)" class="tour-end__tag">{{
              tagOf(choice)
            }}</span></span
          >
          <span class="tour-choice__sub">{{ choice.desc }}</span>
        </span>
        <span class="tour-radio" aria-hidden="true" />
      </button>
    </div>
  </div>
</template>

<script setup>
/**
 * The tour's last screen: where to start (one choice). The tailored session and the automatic
 * repertoire need Lichess.
 */
import { END_CHOICES } from '@/utils/onboarding'

const props = defineProps({
  modelValue: { type: String, required: true },
  linked: { type: Boolean, default: false }
})

const emit = defineEmits({
  /** @param {string} id */
  'update:modelValue': id => typeof id === 'string'
})

/** @param {(typeof END_CHOICES)[number]} choice */
function tagOf(choice) {
  if (choice.id === 'coach' && props.linked) return 'Conseillé'
  return choice.lichess && !props.linked ? 'Lichess requis' : ''
}
</script>

<style scoped lang="scss">
.tour-end__choices {
  display: flex;
  flex-direction: column;
  gap: 9px;
}

.tour-end__choice {
  gap: 12px;
  padding: 12px 14px;
  text-align: left;
}

.tour-end__glyph {
  width: 44px;
  height: 44px;
  flex: none;
  border-radius: 14px;
  background: #4fb2ff;
  color: #1b1530;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
}

.tour-end__text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.tour-end__title {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 7px;
  font-weight: 800;
  font-size: 15px;
}

.tour-end__tag {
  height: 19px;
  padding: 0 7px;
  border-radius: 999px;
  background: #e2f2ff;
  color: #0f6bba;
  font-size: 10.5px;
  font-weight: 800;
  display: flex;
  align-items: center;
}
</style>
