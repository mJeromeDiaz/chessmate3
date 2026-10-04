<template>
  <section id="lichess" class="landing-section">
    <div class="landing-lichess">
      <div class="landing-lichess__coach">
        <img
          v-if="LANDING_IMAGES.aaronWink"
          :src="LANDING_IMAGES.aaronWink"
          alt="Aaron"
          class="landing-lichess__img"
        />
        <div class="landing-bubble landing-lichess__bubble">
          <div class="landing-bubble__label landing-lichess__label">AARON</div>
          <div class="landing-lichess__quote">
            Avec tes parties Lichess, je prépare ton répertoire et des puzzles
            taillés pour toi.
          </div>
        </div>
      </div>
      <div class="landing-lichess__text">
        <div class="landing-kicker">LICHESS</div>
        <h2 class="landing-lichess__title cm-heading"
          >Ton compte Lichess, lié en un clic.</h2
        >
        <div class="landing-lichess__perks">
          <div
            v-for="perk in LICHESS_PERKS"
            :key="perk"
            class="landing-lichess__perk"
          >
            <span class="landing-lichess__check" aria-hidden="true">✓</span
            >{{ perk }}
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { LANDING_IMAGES, LICHESS_PERKS } from '@/utils/landing/content'

/** What a linked Lichess account brings. */
</script>

<style scoped lang="scss">
.landing-lichess {
  max-width: 1200px;
  margin: 0 auto;
  display: flex;
  flex-wrap: wrap;
  gap: 40px;
  align-items: center;
}

.landing-lichess__coach {
  flex: 1 1 300px;
  min-width: 0;
  display: flex;
  gap: 14px;
  align-items: flex-end;
  justify-content: center;
}

.landing-lichess__img {
  width: 150px;
  flex: none;

  @media (max-width: $breakpoint-xs-max) {
    width: 110px;
  }
}

.landing-lichess__bubble {
  border-radius: 22px 22px 22px 4px;
  padding: 14px 18px;
  margin-bottom: 24px;
  max-width: 300px;
}

.landing-lichess__label {
  color: #0f6bba;
}

.landing-lichess__quote {
  font-size: 15px;
  line-height: 1.45;
  text-wrap: pretty;
}

.landing-lichess__text {
  flex: 1 1 380px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.landing-lichess__title {
  margin: 0;
  font-size: clamp(30px, 3.8vw, 44px);
  line-height: 1.05;
  text-wrap: balance;
}

.landing-lichess__perks {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.landing-lichess__perk {
  display: flex;
  gap: 10px;
  align-items: center;
  font-size: 15.5px;
}

.landing-lichess__check {
  width: 26px;
  height: 26px;
  flex: none;
  border-radius: 8px;
  background: var(--cm-lime);
  color: #1b1530;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 13px;
}
</style>
