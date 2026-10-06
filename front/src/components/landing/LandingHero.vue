<template>
  <section class="landing-hero">
    <div class="landing-hero__card">
      <div class="landing-shade landing-hero__shade" />
      <div class="landing-hero__glow" />
      <div class="landing-hero__text">
        <span class="landing-pill">Compatible Lichess</span>
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
        <p class="landing-hero__lead">
          Compose ton entraînement d’échecs, coup par coup.
        </p>
        <div class="landing-actions">
          <LichessButton />
          <router-link
            to="/session/new"
            class="landing-btn landing-btn--light"
            data-testid="landing-see-session"
            >Voir une session</router-link
          >
        </div>
      </div>
      <div v-if="hasCast" class="landing-hero__cast">
        <img
          v-if="LANDING_IMAGES.lizyFull"
          :src="LANDING_IMAGES.lizyFull"
          alt="Lizy"
          class="landing-hero__prof landing-hero__prof--left"
        />
        <img
          v-if="LANDING_IMAGES.albertFull"
          :src="LANDING_IMAGES.albertFull"
          alt="Albert Stein"
          class="landing-hero__prof landing-hero__prof--right"
        />
        <img
          v-if="LANDING_IMAGES.aaronFull"
          :src="LANDING_IMAGES.aaronFull"
          alt="Aaron"
          class="landing-hero__prof landing-hero__prof--center"
        />
      </div>
    </div>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import {
  HERO_SLOGANS,
  HERO_SLOGAN_INTERVAL_MS,
  LANDING_IMAGES
} from '@/utils/landing/content'
import LichessButton from '@/components/landing/LichessButton.vue'

/**
 * The landing page's hero: the slogans in turn, the pitch and the three professors. Both slogans
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

/** Without any full-length picture (not dropped in src/assets/profs yet), no empty space. */
const hasCast = Boolean(
  LANDING_IMAGES.lizyFull ||
  LANDING_IMAGES.albertFull ||
  LANDING_IMAGES.aaronFull
)
</script>

<style scoped lang="scss">
.landing-hero {
  padding: 24px 16px 0;
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
  gap: 24px;
  padding: clamp(32px, 6vw, 72px) clamp(24px, 5vw, 64px) 0;
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
  font-size: clamp(40px, 6vw, 72px);
  line-height: 1;
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
  flex: 1 1 420px;
  min-width: 0;
  height: clamp(300px, 38vw, 460px);
}

.landing-hero__prof {
  position: absolute;
  bottom: -24px;
  height: 72%;

  &--left {
    left: 0;
  }

  &--right {
    right: 0;
  }

  &--center {
    left: 50%;
    bottom: -30px;
    height: 92%;
    transform: translateX(-50%);
  }
}
</style>
