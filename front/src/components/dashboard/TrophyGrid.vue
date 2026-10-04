<template>
  <section class="cm-card trophies" data-testid="trophies">
    <div class="trophies__head">
      <h2 class="cm-card__title">Trophées</h2>
      <span class="trophies__count"
        >{{ unlocked }} / {{ SHOWCASE.badges.length }} débloqués <ShowcaseTag
      /></span>
    </div>
    <div class="trophies__grid">
      <div
        v-for="badge in SHOWCASE.badges"
        :key="badge.name"
        class="trophies__badge"
        :class="{ 'trophies__badge--locked': !badge.unlocked }"
      >
        <div
          class="trophies__icon"
          :style="{ background: badge.bg, color: badge.ink }"
        >
          {{ badge.icon }}
        </div>
        <div class="trophies__name">{{ badge.name }}</div>
        <div class="trophies__desc">{{ badge.desc }}</div>
      </div>
    </div>
  </section>
</template>

<script setup>
import ShowcaseTag from '@/components/dashboard/ShowcaseTag.vue'
import { SHOWCASE } from '@/utils/dashboard/showcase'

const unlocked = SHOWCASE.badges.filter(b => b.unlocked).length
</script>

<style scoped lang="scss">
.trophies {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.trophies__head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 8px;
}

.trophies__count {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-muted);
}

.trophies__grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.trophies__badge {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  padding: 10px 6px;
  border-radius: 16px;
  background: var(--cm-page);
  text-align: center;

  &--locked {
    opacity: 0.45;
  }
}

.trophies__icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 14px;
  font-size: 24px;
}

.trophies__name {
  font-weight: 700;
  font-size: 12px;
  line-height: 1.2;
}

.trophies__desc {
  font-size: 10.5px;
  line-height: 1.2;
  color: var(--cm-muted);
}
</style>
