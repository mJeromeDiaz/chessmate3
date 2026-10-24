<template>
  <section id="lichess" class="landing-section" data-testid="landing-auto">
    <div class="landing-auto">
      <div class="landing-auto__coach">
        <img
          v-if="LANDING_IMAGES.aaronWink"
          :src="LANDING_IMAGES.aaronWink"
          alt="Aaron"
          class="landing-auto__img"
        />
        <div class="landing-bubble landing-auto__bubble">
          <div class="landing-bubble__label landing-auto__label">{{
            $t('landing.auto.who')
          }}</div>
          <div class="landing-auto__quote">
            {{ $t('landing.auto.quote') }}
          </div>
        </div>
      </div>

      <div class="landing-auto__text">
        <div class="landing-kicker">{{ $t('landing.auto.kicker') }}</div>
        <h2 class="landing-auto__title cm-heading">{{
          $t('landing.auto.title')
        }}</h2>
        <ul class="landing-auto__points">
          <li v-for="point in points()" :key="point">
            <span class="landing-auto__check" aria-hidden="true">✓</span
            >{{ point }}
          </li>
        </ul>
        <div class="landing-actions">
          <AutoRepertoireButton
            class="landing-auto__btn"
            data-testid="landing-auto-card"
            >{{ $t('landing.auto.repertoireButton') }}</AutoRepertoireButton
          >
          <AutoRepertoireButton
            :path="COACH_PATH"
            class="landing-auto__btn"
            data-testid="landing-auto-session-btn"
            >{{ $t('landing.auto.sessionButton') }}</AutoRepertoireButton
          >
        </div>
        <p class="landing-auto__note">
          {{ $t('landing.auto.note') }}
        </p>
      </div>
    </div>
  </section>
</template>

<script setup>
import AutoRepertoireButton from '@/components/landing/AutoRepertoireButton.vue'
import { COACH_PATH } from '@/utils/auth/authFlow'
import { LANDING_IMAGES } from '@/utils/landing/content'
import { t } from '@/i18n'

/**
 * What the user's Lichess games bring: the repertoire built from them (docs/REPERTOIRE.md,
 * "Repertoire from my games") and the tailored session generated from them (docs/COACH.md). Both
 * buttons sign in with Lichess, then open that page.
 */

/** The three points, in the current language. */
function points() {
  return [0, 1, 2].map(i => t(`landing.auto.points.${i}`))
}
</script>

<style scoped lang="scss">
.landing-auto {
  max-width: 1200px;
  margin: 0 auto;
  display: flex;
  flex-wrap: wrap;
  gap: 40px;
  align-items: center;
}

.landing-auto__coach {
  flex: 1 1 300px;
  min-width: 0;
  display: flex;
  gap: 14px;
  align-items: flex-end;
  justify-content: center;
}

.landing-auto__img {
  width: 150px;
  flex: none;

  @media (max-width: $breakpoint-xs-max) {
    width: 110px;
  }
}

.landing-auto__bubble {
  background: var(--cm-surface);
  border-radius: 22px 22px 22px 4px;
  padding: 14px 18px;
  margin-bottom: 24px;
  max-width: 300px;
}

.landing-auto__label {
  color: #0f6bba;

  body.body--dark & {
    color: #4fb2ff;
  }
}

.landing-auto__quote {
  font-size: 15px;
  line-height: 1.45;
  text-wrap: pretty;
}

.landing-auto__text {
  flex: 1 1 380px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.landing-auto__title {
  margin: 0;
  font-size: clamp(30px, 3.8vw, 44px);
  line-height: 1.05;
  text-wrap: balance;
}

.landing-auto__points {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 10px;

  li {
    display: flex;
    gap: 10px;
    align-items: center;
    font-size: 15.5px;
  }
}

.landing-auto__check {
  width: 26px;
  height: 26px;
  flex: none;
  border-radius: 8px;
  background: #c6f432;
  color: #1b1530;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 13px;
}

.landing-auto__btn {
  min-height: 52px;
  padding: 10px 20px;
  border-radius: 16px;
  font-size: 15.5px;
  white-space: normal;
  line-height: 1.25;
  box-shadow: 0 8px 22px -10px rgba(30, 160, 110, 0.8);
}

.landing-auto__note {
  margin: 0;
  font-size: 13.5px;
  color: var(--cm-muted);
  text-wrap: pretty;
}
</style>
