<template>
  <header class="cs-header" :class="{ 'cs-header--dark': dark }">
    <button
      type="button"
      class="cs-header__back"
      :aria-label="$t('repertoireAuto.header.back')"
      data-testid="coach-back"
      @click="emit('back')"
      >←</button
    >
    <div class="cs-header__main">
      <div class="cs-header__line">
        <span class="cs-header__title">{{ $t('coach.header.title') }}</span>
        <span class="cs-header__step" data-testid="coach-step">{{
          $t('repertoireAuto.header.step', {
            n: index + 1,
            total: STEPS.length,
            label: $t(`coach.header.steps.${step}`)
          })
        }}</span>
      </div>
      <div class="cs-header__bars" aria-hidden="true">
        <span
          v-for="(s, i) in STEPS"
          :key="s"
          class="cs-header__bar"
          :class="{ 'cs-header__bar--on': i <= index }"
        />
      </div>
    </div>
  </header>
</template>

<script setup>
/**
 * The tailored session's header (design "Session perso"): back, the step and its progress.
 */
import { computed } from 'vue'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/composables/coach/useCoachSession').CoachStep>} */
  step: { type: String, required: true },
  /** On the dark screens (analysis, composition). */
  dark: { type: Boolean, default: false }
})
const emit = defineEmits(['back'])

const STEPS = ['connect', 'scan', 'compose', 'session', 'go']
const index = computed(() => Math.max(0, STEPS.indexOf(props.step)))
</script>

<style scoped lang="scss">
.cs-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 0 14px;
  color: var(--cs-ink);
  transition: color 0.4s;

  &--dark {
    color: #fff;
  }
}

.cs-header__back {
  width: 42px;
  height: 42px;
  flex: none;
  border-radius: 14px;
  border: none;
  background: var(--cs-card);
  color: inherit;
  font-size: 18px;
  font-weight: 800;
  cursor: pointer;

  .cs-header--dark & {
    background: rgba(255, 255, 255, 0.16);
  }

  &:focus-visible {
    outline: 3px solid var(--cs-accent);
    outline-offset: 2px;
  }
}

.cs-header__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.cs-header__line {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
}

.cs-header__title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;
}

.cs-header__step {
  font-size: 12px;
  font-weight: 700;
}

.cs-header__bars {
  display: flex;
  gap: 4px;
}

.cs-header__bar {
  flex: 1;
  height: 5px;
  border-radius: 5px;
  background: var(--cs-dash);
  transition: background 0.4s;

  .cs-header--dark & {
    background: rgba(255, 255, 255, 0.22);
  }

  &--on {
    background: var(--cs-brand);

    .cs-header--dark & {
      background: var(--cs-accent);
    }
  }
}
</style>
