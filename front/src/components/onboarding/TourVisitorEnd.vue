<template>
  <div class="tour-visitor-end" data-testid="tour-visitor-end">
    <div class="tour-visitor-end__label">{{
      $t('onboarding.visitorEnd.label')
    }}</div>
    <ul class="tour-visitor-end__list">
      <li
        v-for="(choice, i) in END_CHOICES"
        :key="choice.id"
        class="tour-visitor-end__item tour-in"
        :style="{ animationDelay: `${0.15 + i * 0.08}s` }"
      >
        <span class="tour-visitor-end__glyph" aria-hidden="true">{{
          choice.glyph
        }}</span>
        <span class="tour-visitor-end__text">
          <span class="tour-visitor-end__title">{{ choice.title }}</span>
          <span class="tour-choice__sub">{{ choice.desc }}</span>
        </span>
      </li>
    </ul>
    <router-link
      to="/login"
      class="tour-visitor-end__login"
      data-testid="tour-visitor-login"
      >{{ $t('onboarding.visitorEnd.login') }}</router-link
    >
  </div>
</template>

<script setup>
/**
 * The last screen of a visitor's discovery tour: what an account opens (the real tour's starting
 * points, shown, not chosen), and a sign-in link for those who have one. The main button signs up.
 */
import { END_CHOICES } from '@/utils/onboarding'
</script>

<style scoped lang="scss">
.tour-visitor-end {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.tour-visitor-end__label {
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.04em;
  color: var(--cm-muted);
  text-transform: uppercase;
}

.tour-visitor-end__list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.tour-visitor-end__item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border-radius: 16px;
  background: var(--cm-page);
  border: 1px solid var(--cm-line);
}

.tour-visitor-end__glyph {
  width: 40px;
  height: 40px;
  flex: none;
  border-radius: 12px;
  background: #4fb2ff;
  color: #1b1530;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
}

.tour-visitor-end__text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.tour-visitor-end__title {
  font-weight: 800;
  font-size: 14.5px;
}

.tour-visitor-end__login {
  align-self: center;
  font-weight: 700;
  font-size: 14px;
  color: var(--cm-brand);
}
</style>
