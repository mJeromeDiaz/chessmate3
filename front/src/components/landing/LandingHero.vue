<template>
  <section class="landing-hero">
    <div class="landing-hero__card">
      <div class="landing-shade landing-hero__shade" />
      <div class="landing-hero__glow" />
      <div class="landing-hero__head">
        <span class="landing-pill">{{ $t('landing.hero.pill') }}</span>
        <h1 class="landing-hero__title cm-heading" data-testid="landing-slogan">
          <span
            v-for="(slogan, i) in HERO_SLOGANS"
            :key="slogan"
            class="landing-hero__slogan"
            :class="{ 'landing-hero__slogan--shown': i === current }"
            :aria-hidden="i !== current"
            >{{ slogan }}</span
          >
        </h1>
      </div>
      <div class="landing-hero__text">
        <p class="landing-hero__lead">
          {{ $t('landing.hero.lead') }}
        </p>
        <div class="landing-actions">
          <router-link
            :to="TOUR_PATH"
            class="landing-btn landing-btn--dark"
            data-testid="landing-tour"
            >{{ $t('landing.hero.tour') }}</router-link
          >
          <TryWithoutAccount class="landing-btn--light" />
          <button
            type="button"
            class="landing-btn landing-btn--light"
            data-testid="landing-discover"
            @click="scrollToSection('parcours')"
            >{{ $t('landing.hero.discover') }}</button
          >
        </div>
      </div>
      <div class="landing-hero__cast" data-testid="landing-hero-cast">
        <img
          v-for="prof in HERO_CAST"
          :key="prof.name"
          :src="prof.image"
          :alt="prof.name"
          class="landing-hero__prof"
          :style="{
            left: `${prof.x}%`,
            height: `${prof.height}%`,
            zIndex: prof.front ? 2 : 1
          }"
        />
      </div>
    </div>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import TryWithoutAccount from '@/components/guest/TryWithoutAccount.vue'
import { TOUR_PATH } from '@/utils/auth/authFlow'
import {
  HERO_CAST,
  HERO_SLOGANS,
  HERO_SLOGAN_INTERVAL_MS,
  scrollToSection
} from '@/utils/landing/content'

/**
 * The landing page's hero: the slogans in turn, the pitch, the guided tour (open to visitors,
 * docs/ONBOARDING.md) and the paths, and the four professors. Both slogans
 * share one grid cell (the title keeps the height of the longer one, no jump). Paused while the
 * tab is hidden; with reduced motion, no rotation: one slogan picked at random per visit.
 */

const current = ref(0)
let timer = null

function start() {
  stop()
  timer = setInterval(() => {
    current.value = (current.value + 1) % HERO_SLOGANS.length
  }, HERO_SLOGAN_INTERVAL_MS)
}

function stop() {
  if (timer !== null) {
    clearInterval(timer)
    timer = null
  }
}

function onVisibility() {
  if (document.hidden) {
    stop()
  } else {
    start()
  }
}

onMounted(() => {
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    current.value = Math.floor(Math.random() * HERO_SLOGANS.length)
    return
  }
  start()
  document.addEventListener('visibilitychange', onVisibility)
})

onBeforeUnmount(() => {
  stop()
  document.removeEventListener('visibilitychange', onVisibility)
})
</script>

<style scoped lang="scss">
.landing-hero {
  padding: 24px 16px 0;

  // Edge to edge on a phone, right under the header.
  @media (max-width: $breakpoint-xs-max) {
    padding: 0;
  }
}

.landing-hero__card {
  max-width: 1200px;
  margin: 0 auto;
  position: relative;
  overflow: hidden;
  background: #8b6bff;
  border-radius: 36px;
  color: #fff;
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 20px 24px;
  padding: clamp(32px, 6vw, 72px) clamp(24px, 5vw, 64px) 0;

  @media (max-width: $breakpoint-xs-max) {
    border-radius: 0;
    padding: 32px 16px 0;
  }
}

.landing-hero__shade {
  width: 65%;
  background: #5a3be0;
  opacity: 0.6;
}

.landing-hero__glow {
  position: absolute;
  right: 4%;
  bottom: -200px;
  width: 640px;
  height: 520px;
  background: radial-gradient(
    circle,
    rgba(255, 255, 255, 0.28),
    transparent 65%
  );
  pointer-events: none;
}

// The pill and the slogans over the card's whole width; the pitch, the buttons and the professors
// below.
.landing-hero__head {
  position: relative;
  flex: 0 0 100%;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.landing-hero__text {
  position: relative;
  flex: 1 1 380px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 20px;
  padding-bottom: clamp(32px, 6vw, 72px);
}

.landing-hero__title {
  margin: 0;
  display: grid;
  font-size: clamp(40px, 5.6vw, 76px);
  line-height: 0.98;
  letter-spacing: -0.01em;
  text-wrap: balance;
}

.landing-hero__slogan {
  grid-area: 1 / 1;
  opacity: 0;
  transition: opacity 0.6s ease;

  &--shown {
    opacity: 1;
  }

  @media (prefers-reduced-motion: reduce) {
    transition: none;
  }
}

.landing-hero__lead {
  margin: 0;
  font-size: clamp(16px, 1.6vw, 19px);
  line-height: 1.5;
  max-width: 520px;
  text-wrap: pretty;
}

.landing-hero__cast {
  position: relative;
  flex: 0 1 480px;
  min-width: 300px;
  height: clamp(220px, 24vw, 310px);
  margin-left: auto;

  // Under the buttons on a phone, across the card's width.
  @media (max-width: $breakpoint-xs-max) {
    flex-basis: 100%;
    min-width: 0;
    height: 250px;
    margin: 0 -6px;
  }
}

// Each professor stands at its own place (HERO_CAST), the two in front a little taller.
.landing-hero__prof {
  position: absolute;
  bottom: -16px;
  max-width: 40%;
  object-fit: contain;
  object-position: bottom;
  transform: translateX(-50%);
}
</style>
