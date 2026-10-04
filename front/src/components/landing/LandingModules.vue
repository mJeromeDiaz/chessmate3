<template>
  <section id="modules" class="landing-section">
    <div class="landing-section__inner">
      <div class="landing-modules__head">
        <div class="landing-modules__heading">
          <div class="landing-kicker">{{ MODULES.length }} MODULES</div>
          <h2 class="landing-h2 cm-heading"
            >Chaque module a sa couleur, son prof et ses réglages.</h2
          >
        </div>
        <p class="landing-modules__lead">
          Durée, Elo, thèmes, couleur jouée, chrono par coup, répétitions
          espacées : tu règles, on enchaîne.
        </p>
      </div>
      <div class="landing-modules__grid">
        <div
          v-for="module in MODULES"
          :key="module.id"
          class="landing-module"
          :style="{ background: module.bg, color: module.ink }"
          :data-testid="`landing-module-${module.id}`"
        >
          <div
            class="landing-shade landing-module__shade"
            :style="{ background: module.deep }"
          />
          <div class="landing-module__glow" />
          <div class="landing-module__title cm-heading">{{ module.title }}</div>
          <div class="landing-module__desc">{{ module.desc }}</div>
          <span v-if="!module.available" class="landing-module__soon"
            >Bientôt</span
          >
          <ProfAvatar
            class="landing-module__avatar"
            :image="module.image"
            :deep="module.deep"
            :ink="module.ink"
            :glyph="module.glyph"
          />
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { MODULES } from '@/utils/session/catalog'
import ProfAvatar from '@/components/session/ProfAvatar.vue'

/** The session builder's catalogue, shown in full; modules not built yet are tagged "Bientôt". */
</script>

<style scoped lang="scss">
.landing-modules__head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
}

.landing-modules__heading {
  max-width: 620px;
}

.landing-modules__lead {
  margin: 0;
  max-width: 380px;
  font-size: 16px;
  line-height: 1.5;
  color: var(--cm-ink-soft);
  text-wrap: pretty;
}

.landing-modules__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 270px), 1fr));
  gap: 12px;
}

.landing-module {
  position: relative;
  overflow: hidden;
  border-radius: 24px;
  padding: 18px 100px 18px 20px;
  min-height: 104px;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 4px;
}

.landing-module__shade {
  width: 55%;
  opacity: 0.55;
}

.landing-module__glow {
  position: absolute;
  right: -20px;
  top: 50%;
  width: 150px;
  height: 150px;
  transform: translateY(-50%);
  background: radial-gradient(
    circle,
    rgba(255, 255, 255, 0.28),
    transparent 65%
  );
  pointer-events: none;
}

.landing-module__title {
  position: relative;
  font-size: 19px;
  line-height: 1.15;
}

.landing-module__desc {
  position: relative;
  font-size: 13.5px;
  line-height: 1.4;
  text-wrap: pretty;
}

.landing-module__soon {
  position: relative;
  align-self: flex-start;
  margin-top: 4px;
  padding: 2px 9px;
  border-radius: 999px;
  background: #fff;
  color: #1b1530;
  font-size: 11px;
  font-weight: 700;
}

.landing-module__avatar {
  position: absolute;
  right: 4px;
  bottom: -10px;
  width: 92px;
  height: 92px;
}
</style>
