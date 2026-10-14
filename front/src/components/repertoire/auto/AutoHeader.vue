<template>
  <div class="ra-header" :class="{ 'ra-header--dark': dark }">
    <button
      type="button"
      class="ra-header__back"
      :aria-label="$t('repertoireAuto.header.back')"
      data-testid="auto-back"
      @click="emit('back')"
      >←</button
    >
    <div class="ra-header__main">
      <div class="ra-header__row">
        <span class="ra-header__title">{{
          $t('repertoireAuto.header.title')
        }}</span>
        <span class="ra-header__step" data-testid="auto-step">{{
          $t('repertoireAuto.header.step', {
            n: index + 1,
            total: STEPS.length,
            label: STEP_LABELS[step]
          })
        }}</span>
      </div>
      <div class="ra-header__bars">
        <div
          v-for="(s, i) in STEPS"
          :key="s"
          class="ra-header__bar"
          :class="{ 'ra-header__bar--on': i <= index }"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * The header of the "repertoire from my games" page (design "Repertoire auto"): back, title,
 * step and the five steps' progress.
 */
import { computed } from 'vue'
import { STEPS, STEP_LABELS } from '@/utils/repertoireAuto'

const props = defineProps({
  /** connect, scan, select, gen, tree */
  step: { type: String, required: true },
  /** Light text over the dark screens (scan, generation). */
  dark: { type: Boolean, default: false }
})
const emit = defineEmits(['back'])

const index = computed(() => Math.max(0, STEPS.indexOf(props.step)))
</script>

<style scoped lang="scss">
.ra-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px 0 10px;
  color: var(--ra-ink);
  transition: color 0.4s;

  &--dark {
    color: #fff;
  }
}

.ra-header__back {
  width: 42px;
  height: 42px;
  flex: none;
  border-radius: 14px;
  border: none;
  background: var(--ra-card);
  color: inherit;
  font-size: 18px;
  font-weight: 800;
  cursor: pointer;

  .ra-header--dark & {
    background: rgba(255, 255, 255, 0.16);
  }
}

.ra-header__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.ra-header__row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
}

.ra-header__title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 17px;
}

.ra-header__step {
  font-size: 12px;
  font-weight: 700;
}

.ra-header__bars {
  display: flex;
  gap: 4px;
}

.ra-header__bar {
  flex: 1;
  height: 5px;
  border-radius: 5px;
  background: var(--ra-dash);
  transition: background 0.4s;

  &--on {
    background: var(--ra-brand);
  }

  .ra-header--dark & {
    background: rgba(255, 255, 255, 0.22);

    &--on {
      background: var(--ra-accent);
    }
  }
}
</style>
