<template>
  <div
    class="boss-banner"
    :style="{ '--dark': colors.dark, '--glow': colors.glow }"
    data-testid="arena-boss-banner"
    :data-mood="mood"
  >
    <span class="boss-banner__glow" aria-hidden="true" />
    <div class="boss-banner__talk">
      <div class="boss-banner__face">
        <img
          v-if="face"
          :key="`${mood}-${reactionKey}`"
          :src="face"
          alt=""
          class="boss-banner__img"
          draggable="false"
        />
        <span v-else class="boss-banner__glyph" aria-hidden="true">{{
          glyph
        }}</span>
      </div>
      <p
        :key="`line-${reactionKey}`"
        class="boss-banner__bubble"
        data-testid="arena-boss-line"
      >
        {{ line }}
      </p>
    </div>
    <div class="boss-banner__row">
      <div class="boss-banner__who">
        <div class="boss-banner__name">{{ name }}</div>
        <div class="boss-banner__meta">{{ meta }}</div>
      </div>
      <div
        class="boss-banner__clock"
        :class="{ 'boss-banner__clock--on': running }"
        data-testid="arena-boss-clock"
      >
        {{ clock }}
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * The boss in a game of the arena (design « Défier les boss »): its face in the mood of its last
 * reaction, its words in a bubble, its name, strength and side, its clock (lit while it has the
 * move).
 */
import { computed } from 'vue'
import { bossMood } from '@/utils/arena'

const props = defineProps({
  character: { type: String, required: true },
  name: { type: String, required: true },
  /** Its Elo and side. */
  meta: { type: String, required: true },
  /** @type {import('vue').PropType<import('@/utils/mindMapPath').Mood>} */
  mood: { type: String, default: 'neutral' },
  line: { type: String, default: '' },
  /** Grows with each reaction: the face and the bubble pop again. */
  reactionKey: { type: Number, default: 0 },
  glyph: { type: String, default: '♚' },
  /** @type {import('vue').PropType<import('@/utils/arena').Palette>} */
  colors: { type: Object, required: true },
  clock: { type: String, required: true },
  running: { type: Boolean, default: false }
})

const face = computed(() =>
  bossMood(props.character, /** @type {any} */ (props.mood))
)
</script>

<style scoped lang="scss">
.boss-banner {
  position: relative;
  background: var(--dark);
  color: #fff;
  padding: 12px 16px 14px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  overflow: hidden;
}

.boss-banner__glow {
  position: absolute;
  left: -40px;
  top: -60px;
  width: 260px;
  height: 260px;
  background: radial-gradient(circle, var(--glow), transparent 65%);
}

.boss-banner__talk {
  position: relative;
  display: flex;
  align-items: flex-end;
  gap: 12px;
}

.boss-banner__face {
  width: 92px;
  height: 92px;
  flex: none;
  display: flex;
  align-items: flex-end;
  justify-content: center;
}

.boss-banner__img {
  height: 92px;
  max-width: 110px;
  object-fit: contain;
  object-position: bottom;
  animation: boss-banner-pop 0.35s cubic-bezier(0.34, 1.6, 0.64, 1);
}

.boss-banner__glyph {
  font-size: 72px;
  line-height: 1;
}

.boss-banner__bubble {
  flex: 1;
  margin: 0 0 8px;
  background: #fff;
  color: #1b1530;
  border-radius: 16px 16px 16px 4px;
  padding: 8px 12px;
  font-size: 13.5px;
  line-height: 1.35;
  font-weight: 500;
  text-wrap: pretty;
  animation: boss-banner-pop 0.3s ease-out;
}

.boss-banner__row {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.boss-banner__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 16px;
  line-height: 1.1;
}

.boss-banner__meta {
  font-size: 11.5px;
  color: #c9c3de;
  font-weight: 700;
}

.boss-banner__clock {
  height: 34px;
  padding: 0 12px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.14);
  color: #fff;
  font-weight: 800;
  font-size: 16px;
  display: flex;
  align-items: center;
  font-variant-numeric: tabular-nums;
}

.boss-banner__clock--on {
  background: var(--cm-lime);
  color: #1b1530;
}

@keyframes boss-banner-pop {
  0% {
    transform: scale(0.6);
    opacity: 0;
  }
  100% {
    transform: scale(1);
    opacity: 1;
  }
}

@media (prefers-reduced-motion: reduce) {
  .boss-banner__img,
  .boss-banner__bubble {
    animation: none;
  }
}

@media (min-width: 1024px) {
  .boss-banner {
    border-radius: 22px;
    padding: 18px;
    gap: 14px;
  }
  .boss-banner__face {
    width: 120px;
    height: 120px;
  }
  .boss-banner__img {
    height: 120px;
    max-width: 140px;
  }
  .boss-banner__bubble {
    font-size: 15px;
    padding: 12px 14px;
  }
  .boss-banner__name {
    font-size: 22px;
  }
  .boss-banner__meta {
    font-size: 12.5px;
  }
  .boss-banner__clock {
    height: 44px;
    padding: 0 16px;
    font-size: 22px;
  }
}
</style>
