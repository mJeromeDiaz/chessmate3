<template>
  <section id="lichess" class="landing-section" data-testid="landing-auto">
    <div class="landing-section__inner">
      <div class="landing-auto__head">
        <div class="landing-kicker">{{ $t('landing.auto.kicker') }}</div>
        <h2 class="landing-h2 cm-heading">{{ $t('landing.auto.title') }}</h2>
      </div>

      <div class="landing-auto__body">
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

        <div class="landing-auto__cards">
          <article
            class="landing-auto__card landing-auto__card--live"
            data-testid="landing-auto-repertoire"
          >
            <div class="landing-auto__card-kicker">{{
              $t('landing.auto.repertoireKicker')
            }}</div>
            <h3 class="landing-auto__card-title cm-heading">{{
              $t('landing.auto.repertoireTitle')
            }}</h3>
            <p class="landing-auto__card-text">
              {{ $t('landing.auto.repertoireText') }}
            </p>
            <ul class="landing-auto__points">
              <li v-for="point in points('repertoirePoints')" :key="point">
                <span class="landing-auto__check" aria-hidden="true">✓</span
                >{{ point }}
              </li>
            </ul>
            <AutoRepertoireButton
              class="landing-auto__btn"
              data-testid="landing-auto-card"
              >{{ $t('landing.auto.repertoireButton') }}</AutoRepertoireButton
            >
          </article>

          <article
            class="landing-auto__card landing-auto__card--live"
            data-testid="landing-auto-session"
          >
            <div class="landing-auto__card-kicker">{{
              $t('landing.auto.sessionKicker')
            }}</div>
            <h3 class="landing-auto__card-title cm-heading">{{
              $t('landing.auto.sessionTitle')
            }}</h3>
            <p class="landing-auto__card-text">
              {{ $t('landing.auto.sessionText') }}
            </p>
            <ul class="landing-auto__points">
              <li v-for="point in points('sessionPoints')" :key="point">
                <span class="landing-auto__check" aria-hidden="true">✓</span
                >{{ point }}
              </li>
            </ul>
            <AutoRepertoireButton
              :path="COACH_PATH"
              class="landing-auto__btn"
              data-testid="landing-auto-session-btn"
              >{{ $t('landing.auto.sessionButton') }}</AutoRepertoireButton
            >
          </article>
        </div>
      </div>

      <p class="landing-auto__note">
        {{ $t('landing.auto.note') }}
      </p>
    </div>
  </section>
</template>

<script setup>
import AutoRepertoireButton from '@/components/landing/AutoRepertoireButton.vue'
import { COACH_PATH } from '@/utils/auth/authFlow'
import { LANDING_IMAGES } from '@/utils/landing/content'
import { t } from '@/i18n'

/**
 * What the user's Lichess games bring (docs/REPERTOIRE.md, "Repertoire from my games"): the
 * repertoire built from them, and the tailored session generated from them (docs/COACH.md).
 */

/**
 * A card's three points, in the current language.
 *
 * @param {'repertoirePoints'|'sessionPoints'} list
 */
function points(list) {
  return [0, 1, 2].map(i => t(`landing.auto.${list}.${i}`))
}
</script>

<style scoped lang="scss">
.landing-auto__body {
  display: flex;
  flex-wrap: wrap;
  gap: 32px;
  align-items: center;
}

.landing-auto__coach {
  flex: 1 1 280px;
  min-width: 0;
  display: flex;
  gap: 14px;
  align-items: flex-end;
  justify-content: center;
}

.landing-auto__img {
  width: 170px;
  flex: none;

  @media (max-width: $breakpoint-xs-max) {
    width: 110px;
  }
}

.landing-auto__bubble {
  border-radius: 22px 22px 22px 4px;
  padding: 14px 18px;
  margin-bottom: 24px;
  max-width: 300px;
}

.landing-auto__label {
  color: #0f6bba;
}

.landing-auto__quote {
  font-size: 15px;
  line-height: 1.45;
  text-wrap: pretty;
}

.landing-auto__cards {
  flex: 2 1 560px;
  min-width: 0;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr));
  gap: 14px;
}

.landing-auto__card {
  border-radius: 24px;
  padding: 22px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  border: 2px solid var(--cm-line);

  &--live {
    background: color-mix(in srgb, #5ee6b0 16%, var(--cm-surface));
    border-color: color-mix(in srgb, #5ee6b0 55%, var(--cm-surface));
  }

  &--soon {
    background: var(--cm-subtle);
    color: var(--cm-ink-soft);
  }
}

.landing-auto__card-kicker {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: var(--cm-muted);
}

.landing-auto__card-title {
  margin: 0;
  font-size: 24px;
  line-height: 1.1;
  color: var(--cm-ink);
}

.landing-auto__card-text {
  margin: 0;
  font-size: 15px;
  line-height: 1.5;
  text-wrap: pretty;
}

.landing-auto__points {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 8px;

  li {
    display: flex;
    gap: 10px;
    align-items: center;
    font-size: 14.5px;
  }
}

.landing-auto__check {
  width: 24px;
  height: 24px;
  flex: none;
  border-radius: 8px;
  background: #5ee6b0;
  color: #0b3b29;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 12px;
}

.landing-auto__btn {
  align-self: flex-start;
  margin-top: auto;
  height: 52px;
  padding: 0 20px;
  border-radius: 16px;
  font-size: 15.5px;
  white-space: normal;
  box-shadow: 0 8px 22px -10px rgba(30, 160, 110, 0.8);
}

.landing-auto__note {
  margin: 0;
  font-size: 13.5px;
  color: var(--cm-muted);
  text-wrap: pretty;
}
</style>
