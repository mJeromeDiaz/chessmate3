<template>
  <div class="level-banner" data-testid="level-banner">
    <div class="level-banner__shade" />
    <div class="level-banner__hello gt-sm"> Bonjour 👋&#xFE0E; </div>
    <template v-if="bar">
      <div class="level-banner__level">
        <span class="level-banner__title" data-testid="level-title"
          >Niveau {{ bar.level }}</span
        >
        <span class="level-banner__rank">{{ bar.rank }}</span>
      </div>
      <div class="level-banner__bar">
        <div :style="{ width: `${bar.percent}%` }" />
      </div>
      <div class="level-banner__foot">
        <span data-testid="level-xp">{{ bar.xpLabel }}</span>
        <span>{{ bar.next }}</span>
      </div>
    </template>
    <div v-else class="level-banner__level">
      <span class="level-banner__title">Niveau…</span>
    </div>
    <img :src="mascot" alt="" class="level-banner__mascot" />
  </div>
</template>

<script setup>
/** The dashboard's banner: level, rank and XP towards the next level (docs/GAMIFICATION.md). */
import { computed } from 'vue'
import { useGamificationStore } from '@/stores/gamification'
import { levelBar } from '@/utils/gamification'
import { profImage } from '@/utils/prof/images'

const gamification = useGamificationStore()
const bar = computed(() =>
  gamification.summary ? levelBar(gamification.summary) : null
)
const mascot = profImage('aaron-laugh')
</script>

<style scoped lang="scss">
.level-banner {
  position: relative;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px 110px 16px 18px;
  border-radius: 24px;
  background: var(--cm-hero);
  color: #fff;

  > * {
    position: relative;
  }

  @media (min-width: $breakpoint-md-min) {
    gap: 10px;
    padding: 22px 190px 22px 24px;
    border-radius: 26px;
  }
}

.level-banner__shade {
  position: absolute;
  inset: 0 0 0 auto;
  width: 60%;
  background: var(--cm-hero-deep);
  opacity: 0.6;
  mask-image: linear-gradient(to right, transparent, #000);
}

.level-banner__hello {
  font-size: 13px;
}

.level-banner__level {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 12px;
}

.level-banner__title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 24px;
  line-height: 1;

  @media (min-width: $breakpoint-md-min) {
    font-size: 30px;
  }
}

.level-banner__rank {
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--cm-lime);
  color: #1b1530;
  font-weight: 700;
  font-size: 12px;
}

.level-banner__bar {
  height: 10px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.25);
  overflow: hidden;

  > div {
    height: 100%;
    border-radius: 10px;
    background: var(--cm-lime);
  }
}

.level-banner__foot {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 4px 12px;
  font-size: 12.5px;

  @media (min-width: $breakpoint-md-min) {
    font-size: 13px;
  }
}

.level-banner__mascot {
  position: absolute;
  right: 2px;
  bottom: -6px;
  width: 104px;

  @media (min-width: $breakpoint-md-min) {
    right: 20px;
    bottom: -8px;
    width: 150px;
  }
}
</style>
