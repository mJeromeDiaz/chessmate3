<template>
  <div class="quest" data-testid="weekly-quest">
    <img v-if="quest.image" :src="quest.image" alt="" class="quest__prof" />
    <div class="quest__bubble">
      <div class="quest__kicker">
        {{ quest.prof.toUpperCase() }} · DÉFI DE LA SEMAINE <ShowcaseTag />
      </div>
      <div class="quest__text">{{ quest.text }}</div>
      <div class="quest__progress">
        <div class="quest__bar">
          <div :style="{ width: `${(quest.done / quest.goal) * 100}%` }" />
        </div>
        <span>{{ quest.done }}/{{ quest.goal }}</span>
        <span class="quest__reward">+{{ quest.reward }} XP</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import ShowcaseTag from '@/components/dashboard/ShowcaseTag.vue'
import { SHOWCASE } from '@/utils/dashboard/showcase'

const quest = SHOWCASE.quest
</script>

<style scoped lang="scss">
.quest {
  display: flex;
  align-items: flex-end;
  gap: 10px;
}

.quest__prof {
  width: 66px;
  flex: none;

  @media (min-width: $breakpoint-md-min) {
    width: 76px;
  }
}

.quest__bubble {
  flex: 1;
  margin-bottom: 6px;
  padding: 10px 14px;
  border: 2px solid var(--cm-orange-line);
  border-radius: 18px 18px 18px 4px;
  background: var(--cm-surface);
}

.quest__kicker {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 700;
  color: var(--cm-orange-ink);
}

.quest__text {
  font-size: 14px;
  line-height: 1.4;
}

.quest__progress {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 8px;
  font-size: 12px;
  font-weight: 700;
}

.quest__bar {
  flex: 1;
  height: 7px;
  border-radius: 7px;
  background: var(--cm-orange-soft);
  overflow: hidden;

  > div {
    height: 100%;
    background: #ff8a3d;
  }
}

.quest__reward {
  color: var(--cm-brand-deep);
}
</style>
