<template>
  <section id="suivi" class="landing-section">
    <div class="landing-woodpecker">
      <div class="landing-woodpecker__text">
        <span class="landing-pill">Méthode Woodpecker</span>
        <h2 class="landing-woodpecker__title cm-heading"
          >Le même set, toujours plus vite.</h2
        >
        <p class="landing-woodpecker__lead">
          Résous ton set, puis recommence en cycles de plus en plus courts. La
          grille garde la trace de chaque puzzle : rapide, réussi, avec aide ou
          raté.
        </p>
        <router-link
          to="/woodpecker"
          class="landing-btn landing-btn--light landing-woodpecker__cta"
          >Essayer un cycle →</router-link
        >
      </div>
      <div class="landing-woodpecker__board">
        <div class="landing-woodpecker__caption">
          <span>Set de 100 puzzles · Cycle 2</span>
          <span>37 faits · 85 % réussis</span>
        </div>
        <div class="landing-woodpecker__grid">
          <div
            v-for="(color, i) in cells"
            :key="i"
            class="landing-woodpecker__cell"
            :style="{ background: color }"
          />
        </div>
        <div class="landing-woodpecker__legend">
          <span
            v-for="item in WOODPECKER_LEGEND"
            :key="item.label"
            class="landing-woodpecker__key"
            ><span
              class="landing-woodpecker__swatch"
              :style="{ background: item.color }"
            />{{ item.label }}</span
          >
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import {
  WOODPECKER_LEGEND,
  WOODPECKER_TODO,
  woodpeckerCells
} from '@/utils/landing/content'

/** The Woodpecker method, with an example cycle grid. */
const cells = woodpeckerCells(100, 37, WOODPECKER_TODO)
</script>

<style scoped lang="scss">
.landing-woodpecker {
  max-width: 1200px;
  margin: 0 auto;
  background: #1b1530;
  color: #fff;
  border-radius: 36px;
  padding: clamp(28px, 5vw, 56px);
  display: flex;
  flex-wrap: wrap;
  gap: 40px;
  align-items: center;
}

.landing-woodpecker__text {
  flex: 1 1 320px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.landing-woodpecker__title {
  margin: 0;
  font-size: clamp(30px, 3.8vw, 44px);
  line-height: 1.05;
  text-wrap: balance;
}

.landing-woodpecker__lead {
  margin: 0;
  font-size: 16px;
  line-height: 1.55;
  color: #d9d5e8;
  max-width: 440px;
  text-wrap: pretty;
}

.landing-woodpecker__cta {
  align-self: flex-start;
  height: 50px;
  padding: 0 20px;
  border-radius: 16px;
  font-size: 15px;
}

.landing-woodpecker__board {
  flex: 1.4 1 420px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.landing-woodpecker__caption {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
  justify-content: space-between;
  font-size: 13px;
  font-weight: 700;
  color: #b4afc6;
}

.landing-woodpecker__grid {
  display: grid;
  grid-template-columns: repeat(20, minmax(0, 1fr));
  gap: 4px;

  @media (max-width: $breakpoint-xs-max) {
    grid-template-columns: repeat(10, minmax(0, 1fr));
  }
}

.landing-woodpecker__cell {
  aspect-ratio: 1;
  border-radius: 4px;
}

.landing-woodpecker__legend {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  font-size: 12px;
  color: #b4afc6;
}

.landing-woodpecker__key {
  display: flex;
  align-items: center;
  gap: 6px;
}

.landing-woodpecker__swatch {
  width: 11px;
  height: 11px;
  border-radius: 3px;
}
</style>
