<template>
  <button
    type="button"
    class="boss-card"
    :class="`boss-card--${state}`"
    :style="{
      '--dark': colors.dark,
      '--glow': colors.glow,
      '--accent': colors.accent
    }"
    :aria-label="`${name} · ${sub}`"
    :data-testid="`arena-boss-${id}`"
    :data-state="state"
    @click="emit('open')"
  >
    <span class="boss-card__glow" aria-hidden="true" />
    <img
      v-if="picture"
      :src="picture"
      alt=""
      class="boss-card__picture"
      draggable="false"
    />
    <span v-else class="boss-card__glyph" aria-hidden="true">{{ glyph }}</span>
    <span v-if="mystery" class="boss-card__mystery" aria-hidden="true">?</span>
    <span class="boss-card__foot">
      <span class="boss-card__name">{{ name }}</span>
      <span class="boss-card__sub">{{ sub }}</span>
      <span
        v-if="progress !== null"
        class="boss-card__progress"
        role="progressbar"
        :aria-valuenow="Math.round(progress * 100)"
        aria-valuemin="0"
        aria-valuemax="100"
        ><span
          class="boss-card__progress-fill"
          :style="{ width: `${Math.round(progress * 100)}%` }"
      /></span>
    </span>
    <span v-if="state === 'beaten'" class="boss-card__check" aria-hidden="true"
      >✓</span
    >
    <span v-if="state === 'shop' && price" class="boss-card__price"
      ><CrystalGem :size="13" />{{ price }}</span
    >
    <span
      v-if="state === 'locked' || state === 'soon'"
      class="boss-card__lock"
      aria-hidden="true"
    >
      <span class="boss-card__shackle" /><span class="boss-card__body" />
    </span>
  </button>
</template>

<script setup>
/**
 * A boss's card in the arena (design « Défier les boss »): standing on its colours, its name and
 * its state (beaten, its Elo, in the shop with its price, locked, soon). A professor's locked card
 * shows the way to their feat (§ 2 bis).
 */
import CrystalGem from '@/components/shop/CrystalGem.vue'

defineProps({
  id: { type: String, required: true },
  name: { type: String, required: true },
  /** @type {import('vue').PropType<import('@/utils/arena').CardState>} */
  state: { type: String, required: true },
  /** @type {import('vue').PropType<import('@/utils/arena').Palette>} */
  colors: { type: Object, required: true },
  picture: { type: String, default: '' },
  glyph: { type: String, default: '♚' },
  /** Its card's second line. */
  sub: { type: String, required: true },
  price: { type: Number, default: null },
  /** A silhouette with a question mark (Ray). */
  mystery: { type: Boolean, default: false },
  /** A professor's feat on the way, 0 to 1; null: no bar. */
  progress: { type: Number, default: null }
})

const emit = defineEmits(['open'])
</script>

<style scoped lang="scss">
.boss-card {
  position: relative;
  aspect-ratio: 5 / 7;
  border: none;
  padding: 0;
  margin: 0;
  background: var(--dark);
  overflow: hidden;
  cursor: pointer;
  color: #fff;
  border-radius: 16px;
  font: inherit;
  text-align: left;
  transition: transform 0.2s cubic-bezier(0.34, 1.6, 0.64, 1);

  &:hover {
    transform: translateY(-3px) rotate(-1deg);
  }
  &:active {
    transform: translateY(2px) scale(0.98);
  }
  &:focus-visible {
    outline: 3px solid var(--cm-lime);
    outline-offset: 2px;
  }
}

.boss-card__glow {
  position: absolute;
  inset: 0;
  background: radial-gradient(circle at 50% 38%, var(--glow), transparent 62%);
}

.boss-card__picture {
  position: absolute;
  left: -10%;
  top: 4%;
  width: 120%;
  height: 120%;
  object-fit: cover;
  object-position: 50% 0;
  user-select: none;
}

.boss-card__glyph {
  position: absolute;
  left: 50%;
  top: 40%;
  transform: translate(-50%, -50%);
  font-size: 92px;
  line-height: 1;
  color: var(--accent);
  text-shadow: 0 6px 24px rgba(0, 0, 0, 0.45);
}

.boss-card__mystery {
  position: absolute;
  right: 16px;
  top: 14px;
  z-index: 2;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 40px;
  color: var(--cm-lime);
  transform: rotate(12deg);
}

.boss-card__foot {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  z-index: 1;
  padding: 26px 8px 10px;
  background: linear-gradient(
    to bottom,
    rgba(20, 14, 34, 0),
    rgba(20, 14, 34, 0.94) 55%
  );
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  gap: 2px;
}

.boss-card__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 18px;
  line-height: 1.05;
}

.boss-card__sub {
  font-size: 11.5px;
  font-weight: 800;
  letter-spacing: 0.04em;
  color: #9a95ae;
}

.boss-card__progress {
  width: 70%;
  height: 5px;
  margin-top: 5px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.16);
  overflow: hidden;
}

.boss-card__progress-fill {
  display: block;
  height: 100%;
  border-radius: inherit;
  background: var(--accent);
}

.boss-card--open .boss-card__sub {
  color: var(--accent);
}
.boss-card--beaten .boss-card__sub {
  color: var(--cm-lime);
}

.boss-card--shop {
  .boss-card__picture,
  .boss-card__glyph {
    filter: grayscale(0.6) brightness(0.7);
  }
  .boss-card__glow {
    opacity: 0.25;
  }
}

.boss-card--locked,
.boss-card--soon {
  .boss-card__picture,
  .boss-card__glyph {
    filter: grayscale(1) brightness(0.45);
  }
  .boss-card__glow {
    opacity: 0.25;
  }
  .boss-card__name {
    color: #8e89a3;
  }
}

.boss-card__check {
  position: absolute;
  right: 9px;
  top: 9px;
  z-index: 3;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--cm-lime);
  border: 2px solid var(--dark);
  color: #1b1530;
  font-size: 12px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
}

.boss-card__price {
  position: absolute;
  left: 50%;
  top: 9px;
  transform: translateX(-50%);
  z-index: 3;
  height: 24px;
  padding: 0 9px;
  white-space: nowrap;
  border-radius: 999px;
  background: #fff;
  color: #1b1530;
  font-size: 12px;
  font-weight: 800;
  display: flex;
  align-items: center;
  gap: 4px;
}

.boss-card__lock {
  position: absolute;
  left: 50%;
  top: 38%;
  z-index: 2;
  transform: translate(-50%, -50%);
  display: flex;
  flex-direction: column;
  align-items: center;
}

.boss-card__shackle {
  width: 16px;
  height: 12px;
  border: 3px solid rgba(255, 255, 255, 0.85);
  border-bottom: none;
  border-radius: 10px 10px 0 0;
}

.boss-card__body {
  width: 26px;
  height: 20px;
  border-radius: 5px;
  background: rgba(255, 255, 255, 0.85);
}

@media (min-width: 1024px) {
  .boss-card {
    border-radius: 18px;
  }
  .boss-card__name {
    font-size: 20px;
  }
  .boss-card__sub {
    font-size: 12px;
  }
}
</style>
