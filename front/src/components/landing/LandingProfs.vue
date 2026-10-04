<template>
  <section id="profs" class="landing-section">
    <div class="landing-section__inner">
      <div class="landing-profs__heading">
        <div class="landing-kicker">LES PROFS</div>
        <h2 class="landing-h2 cm-heading"
          >Trois personnalités, une même obsession : ta progression.</h2
        >
      </div>
      <div class="landing-profs__grid">
        <router-link
          v-for="prof in LANDING_PROFS"
          :key="prof.slug"
          :to="`/prof/${prof.slug}`"
          class="landing-prof"
          :style="{ background: prof.colors.bg }"
          :data-testid="`landing-prof-${prof.slug}`"
        >
          <div
            class="landing-shade landing-prof__shade"
            :style="{ background: prof.colors.deep }"
          />
          <div class="landing-prof__glow" />
          <div class="landing-prof__role">{{ profRole(prof) }}</div>
          <div class="landing-prof__name cm-heading">{{ prof.name }}</div>
          <div class="landing-prof__persona"
            >{{ prof.glyph }} {{ prof.title }}</div
          >
          <div class="landing-prof__tagline">{{ prof.tagline }}</div>
          <img
            v-if="prof.full"
            :src="prof.full"
            :alt="prof.name"
            class="landing-prof__img"
          />
          <span class="landing-prof__link">Voir le profil →</span>
        </router-link>
      </div>
    </div>
  </section>
</template>

<script setup>
import { LANDING_PROFS, profRole } from '@/utils/landing/content'

/** The three professors, each card opening their profile. */
</script>

<style scoped lang="scss">
.landing-profs__heading {
  max-width: 640px;
}

.landing-profs__grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
  gap: 16px;
}

.landing-prof {
  position: relative;
  overflow: hidden;
  color: #1b1530;
  border-radius: 30px;
  min-height: 440px;
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  text-decoration: none;
  transition: transform 0.12s ease;

  &:hover {
    color: #1b1530;
    transform: translateY(-2px);
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.landing-prof__shade {
  width: 65%;
  opacity: 0.55;
}

.landing-prof__glow {
  position: absolute;
  left: 50%;
  bottom: -120px;
  width: 420px;
  height: 360px;
  transform: translateX(-50%);
  background: radial-gradient(
    circle,
    rgba(255, 255, 255, 0.3),
    transparent 65%
  );
  pointer-events: none;
}

.landing-prof__role,
.landing-prof__name,
.landing-prof__persona,
.landing-prof__tagline {
  position: relative;
}

.landing-prof__role {
  font-size: 12px;
  font-weight: 700;
}

.landing-prof__name {
  font-size: 34px;
  line-height: 1;
}

.landing-prof__persona {
  font-family: var(--cm-heading);
  font-weight: 600;
  font-size: 18px;
}

.landing-prof__tagline {
  font-size: 14.5px;
  line-height: 1.45;
  max-width: 260px;
  text-wrap: pretty;
}

.landing-prof__img {
  position: absolute;
  right: -6px;
  bottom: -24px;
  height: 280px;
}

.landing-prof__link {
  position: absolute;
  left: 24px;
  bottom: 24px;
  background: #fff;
  color: #1b1530;
  font-weight: 700;
  font-size: 13px;
  padding: 8px 13px;
  border-radius: 999px;
}
</style>
