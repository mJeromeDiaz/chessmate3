<template>
  <section class="landing-hero">
    <div class="landing-hero__card">
      <div class="landing-shade landing-hero__shade" />
      <div class="landing-hero__glow" />
      <div class="landing-hero__text">
        <span class="landing-pill">Compatible Lichess</span>
        <h1 class="landing-hero__title cm-heading"
          >Compose ton entraînement d’échecs, coup par coup.</h1
        >
        <p class="landing-hero__lead">
          Puzzles, finales, répertoire, analyse : assemble ta session en
          quelques touches, et laisse tes profs te guider.
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
import { LANDING_IMAGES } from '@/utils/landing/content'
import LichessButton from '@/components/landing/LichessButton.vue'

/** The landing page's hero: the pitch and the three professors. */

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
  font-size: clamp(40px, 6vw, 72px);
  line-height: 1;
  letter-spacing: -0.01em;
  text-wrap: balance;
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
