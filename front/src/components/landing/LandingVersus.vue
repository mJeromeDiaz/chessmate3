<template>
  <section id="versus" class="landing-section" data-testid="landing-versus">
    <div class="landing-section__inner">
      <div class="landing-versus__heading">
        <div class="landing-kicker">{{ $t('landing.versus.kicker') }}</div>
        <h2 class="landing-h2 cm-heading">{{ $t('landing.versus.title') }}</h2>
      </div>

      <div class="landing-versus">
        <div class="landing-versus__side landing-versus__side--profs">
          <div class="landing-versus__side-head">
            <span class="landing-versus__side-title cm-heading">{{
              $t('landing.versus.helpTitle')
            }}</span>
            <span class="landing-versus__side-note">{{
              $t('landing.versus.helpNote')
            }}</span>
          </div>
          <div class="landing-versus__cards">
            <router-link
              v-for="prof in LANDING_PROFS"
              :key="prof.slug"
              :to="`/prof/${prof.slug}`"
              class="landing-versus__card landing-versus__card--prof"
              :style="{ background: prof.colors.bg }"
              :data-testid="`landing-prof-${prof.slug}`"
            >
              <div class="landing-versus__glow" />
              <img
                v-if="prof.full"
                :src="prof.full"
                :alt="prof.name"
                class="landing-versus__img"
                :class="{ 'landing-versus__img--long': prof.slug === 'aaron' }"
              />
              <div class="landing-versus__name cm-heading">{{ prof.name }}</div>
              <div class="landing-versus__role">{{ profRole(prof) }}</div>
            </router-link>
          </div>
        </div>

        <div class="landing-versus__badge-slot" aria-hidden="true">
          <div class="landing-versus__badge cm-heading">VS</div>
        </div>

        <div class="landing-versus__side landing-versus__side--bosses">
          <div class="landing-versus__side-head">
            <span class="landing-versus__side-title cm-heading">{{
              $t('landing.versus.challengeTitle')
            }}</span>
            <span class="landing-versus__side-note">{{
              $t('landing.versus.challengeNote')
            }}</span>
          </div>
          <div class="landing-versus__cards">
            <div
              v-for="boss in LANDING_BOSSES"
              :key="boss.key"
              class="landing-versus__card"
              :style="{ background: boss.dark }"
              :data-testid="`landing-boss-${boss.key}`"
            >
              <div
                class="landing-versus__glow"
                :style="{
                  background: `radial-gradient(circle, ${boss.glow}, transparent 62%)`
                }"
              />
              <img
                v-if="boss.image"
                :src="boss.image"
                :alt="boss.name"
                class="landing-versus__img"
              />
              <div class="landing-versus__name cm-heading">{{ boss.name }}</div>
              <div class="landing-versus__role landing-versus__role--boss">{{
                boss.title
              }}</div>
            </div>
          </div>
        </div>
      </div>
      <p class="landing-versus__more">{{ $t('landing.versus.more') }}</p>
    </div>
  </section>
</template>

<script setup>
import {
  LANDING_BOSSES,
  LANDING_PROFS,
  profRole
} from '@/utils/landing/content'

/**
 * The professors who help (each card opens their public profile) facing the bosses of the paths.
 * Side by side on a wide screen, stacked below 900 px, the "VS" badge on the seam.
 */
</script>

<style scoped lang="scss">
.landing-versus__heading {
  max-width: 700px;
}

.landing-versus {
  position: relative;
  border-radius: 36px;
  overflow: hidden;
  display: flex;

  @media (max-width: 899px) {
    flex-direction: column;
  }
}

.landing-versus__side {
  flex: 1 1 0;
  min-width: 0;
  padding: clamp(20px, 3vw, 36px);
  display: flex;
  flex-direction: column;
  gap: 16px;

  &--profs {
    background: #eee9ff;
    color: #1b1530;
  }

  &--bosses {
    background: #1b1530;
    color: #fff;
  }
}

.landing-versus__side-head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 4px 12px;
  flex-wrap: wrap;
}

.landing-versus__side-title {
  font-size: 26px;

  .landing-versus__side--profs & {
    color: #4a2fe0;
  }

  .landing-versus__side--bosses & {
    color: #ff6fae;
  }
}

.landing-versus__side-note {
  font-size: 13px;

  .landing-versus__side--profs & {
    color: #4c475e;
  }

  .landing-versus__side--bosses & {
    color: #b4afc6;
  }
}

.landing-versus__cards {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.landing-versus__card {
  position: relative;
  overflow: hidden;
  border-radius: 22px;
  height: 280px;
  padding: 14px;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  gap: 2px;
  text-decoration: none;

  @media (max-width: $breakpoint-xs-max) {
    height: 220px;
    padding: 10px;
  }

  &--prof {
    color: #1b1530;
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
}

.landing-versus__glow {
  position: absolute;
  left: 50%;
  top: 40%;
  width: 240px;
  height: 240px;
  transform: translate(-50%, -50%);
  background: radial-gradient(
    circle,
    rgba(255, 255, 255, 0.4),
    transparent 62%
  );
  pointer-events: none;
}

.landing-versus__img {
  position: absolute;
  left: 50%;
  top: 10px;
  height: 200px;
  max-width: 100%;
  object-fit: contain;
  transform: translateX(-50%);

  @media (max-width: $breakpoint-xs-max) {
    height: 150px;
  }

  // Aaron is drawn with long legs: taller, so his head stays the size of the others'.
  &--long {
    height: 250px;

    @media (max-width: $breakpoint-xs-max) {
      height: 190px;
    }
  }
}

.landing-versus__name {
  position: relative;
  font-size: 19px;
  line-height: 1;

  @media (max-width: $breakpoint-xs-max) {
    font-size: 16px;
  }
}

.landing-versus__role {
  position: relative;
  font-size: 11px;
  font-weight: 700;
  line-height: 1.3;
  text-transform: uppercase;

  @media (max-width: $breakpoint-xs-max) {
    font-size: 9.5px;
  }

  &--boss {
    color: #d9d5e8;
  }
}

// The "VS" badge, on the seam of both halves.
.landing-versus__badge-slot {
  position: relative;
  z-index: 2;
  flex: none;
  width: 0;
  display: flex;
  align-items: center;
  justify-content: center;

  @media (max-width: 899px) {
    width: auto;
    height: 0;
  }
}

.landing-versus__badge {
  flex: none;
  transform: rotate(-8deg);
  width: 96px;
  height: 96px;
  border-radius: 50%;
  background: #c6f432;
  color: #1b1530;
  box-shadow: 0 0 0 8px var(--cm-page);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 40px;
  letter-spacing: -0.02em;
  pointer-events: none;

  @media (max-width: $breakpoint-xs-max) {
    width: 72px;
    height: 72px;
    font-size: 30px;
  }
}

.landing-versus__more {
  margin: -12px 0 0;
  font-size: 14px;
  color: var(--cm-muted);
  text-align: right;
}
</style>
