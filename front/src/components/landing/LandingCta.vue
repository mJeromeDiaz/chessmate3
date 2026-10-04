<template>
  <section class="landing-section landing-cta">
    <div class="landing-cta__card">
      <div class="landing-shade landing-cta__shade" />
      <h2 class="landing-cta__title cm-heading"
        >Ta première session t’attend.</h2
      >
      <div class="landing-actions landing-cta__actions">
        <LichessButton />
        <router-link
          to="/register"
          class="landing-btn landing-btn--light"
          data-testid="landing-email"
          >Avec un e-mail</router-link
        >
      </div>
      <img
        v-if="LANDING_IMAGES.lizyJoy"
        :src="LANDING_IMAGES.lizyJoy"
        alt=""
        class="landing-cta__img landing-cta__img--lizy"
      />
      <img
        v-if="LANDING_IMAGES.albertJoy"
        :src="LANDING_IMAGES.albertJoy"
        alt=""
        class="landing-cta__img landing-cta__img--albert"
      />
    </div>
    <footer class="landing-footer">
      <div class="cm-brand-name landing-footer__brand"
        >Chess<span>Mate</span></div
      >
      <nav class="landing-footer__links">
        <router-link to="/conditions">Conditions d’utilisation</router-link>
        <router-link to="/confidentialite">Confidentialité</router-link>
        <router-link to="/contact">Contact</router-link>
      </nav>
    </footer>
  </section>
</template>

<script setup>
import { LANDING_IMAGES } from '@/utils/landing/content'
import LichessButton from '@/components/landing/LichessButton.vue'

/** Closing call to action and the site footer. */
</script>

<style scoped lang="scss">
.landing-cta {
  padding-left: 16px;
  padding-right: 16px;
  padding-bottom: 24px;
}

.landing-cta__card {
  max-width: 1200px;
  margin: 0 auto;
  position: relative;
  overflow: hidden;
  background: #c6f432;
  color: #1b1530;
  border-radius: 36px;
  padding: clamp(32px, 6vw, 64px);
  padding-right: clamp(32px, 30vw, 380px);
  min-height: 260px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 18px;

  @media (max-width: $breakpoint-xs-max) {
    padding-right: 32px;
    padding-bottom: 150px;
  }
}

.landing-cta__shade {
  width: 60%;
  background: #8dba0a;
  opacity: 0.55;
}

.landing-cta__title {
  position: relative;
  margin: 0;
  font-size: clamp(30px, 4.2vw, 50px);
  line-height: 1.02;
  text-wrap: balance;
}

.landing-cta__actions {
  position: relative;
}

.landing-cta__img {
  position: absolute;
  bottom: -8px;

  &--lizy {
    right: clamp(130px, 16vw, 210px);
    height: clamp(120px, 15vw, 190px);
  }

  &--albert {
    right: clamp(8px, 2vw, 24px);
    height: clamp(130px, 17vw, 210px);
  }
}

.landing-footer {
  max-width: 1200px;
  margin: 24px auto 0;
  padding: 0 8px;
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
  color: var(--cm-muted);
}

.landing-footer__brand {
  font-size: 18px;
}

.landing-footer__links {
  display: flex;
  gap: 18px;
  flex-wrap: wrap;

  a {
    color: var(--cm-muted);

    &:hover {
      color: var(--cm-brand);
    }
  }
}
</style>
