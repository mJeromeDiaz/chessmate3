<template>
  <div class="cosmetic" :data-testid="`shop-offer-${offer.slug}`">
    <div class="cosmetic__preview" :style="{ background: preview.bg }">
      <div v-if="preview.kind === 'sounds'" class="cosmetic__bars">
        <span
          v-for="(d, i) in [0.9, 0.6, 1.1, 0.7, 1, 0.5]"
          :key="i"
          class="cosmetic__bar"
          :style="{
            background: i % 2 ? '#1B1530' : '#FFFFFF',
            animationDuration: `${d}s`,
            animationDelay: `${i * 0.1}s`
          }"
        />
      </div>
      <div v-else-if="preview.kind === 'animations'" class="cosmetic__mate">
        <span class="cosmetic__king">♚︎</span>
        <span
          v-for="(star, i) in STARS"
          :key="i"
          class="cosmetic__star"
          :style="{
            left: `${star[0]}px`,
            top: `${star[1]}px`,
            color: star[2],
            animationDelay: `${i * 0.06}s`
          }"
          >✦</span
        >
      </div>
      <template v-else>
        <img v-if="albert" :src="albert" alt="" class="cosmetic__prof" />
        <span class="cosmetic__shine" />
      </template>
    </div>
    <div class="cosmetic__text">
      <span class="cosmetic__name">{{ offer.name }}</span>
      <span class="cosmetic__sub">{{ offer.description }}</span>
    </div>
  </div>
</template>

<script setup>
/**
 * A cosmetic pack "coming soon" (design "Boutique"): a moving preview, its name, nothing to buy
 * yet.
 */
import { computed } from 'vue'
import { profImage } from '@/utils/prof/images'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/shop').ShopOffer>} */
  offer: { type: Object, required: true }
})

const STARS = [
  [-6, 4, '#FFD43B'],
  [56, 0, '#FF6FAE'],
  [62, 46, '#FFFFFF'],
  [-10, 50, '#1B1530']
]
const albert = profImage('albert-cool')

const preview = computed(() => {
  if (props.offer.slug.includes('sound'))
    return { kind: 'sounds', bg: '#8B6BFF' }
  if (props.offer.slug.includes('animation'))
    return { kind: 'animations', bg: '#C6F432' }
  return { kind: 'outfits', bg: '#FFD43B' }
})
</script>

<style scoped lang="scss">
.cosmetic {
  padding: 8px;
  border-radius: 18px;
  background: var(--cm-inset);
  box-shadow: 0 0 0 1px var(--cm-line);
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.cosmetic__preview {
  position: relative;
  height: 104px;
  overflow: hidden;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.cosmetic__bars {
  display: flex;
  align-items: center;
  gap: 5px;
  height: 60px;
}

.cosmetic__bar {
  width: 9px;
  height: 56px;
  border-radius: 5px;
  animation: cosmetic-bar ease-in-out infinite;
}

.cosmetic__mate {
  position: relative;
  width: 70px;
  height: 80px;
  display: flex;
  align-items: flex-end;
  justify-content: center;
}

.cosmetic__king {
  font-size: 64px;
  line-height: 1;
  color: #1b1530;
  transform-origin: 70% 92%;
  animation: cosmetic-tip 2.8s cubic-bezier(0.5, 0, 0.3, 1.4) infinite;
}

.cosmetic__star {
  position: absolute;
  font-size: 16px;
  animation: cosmetic-star 2.8s ease-out infinite;
}

.cosmetic__prof {
  position: absolute;
  bottom: -8px;
  height: 108px;
  animation: cosmetic-bob 2.4s ease-in-out infinite;
}

.cosmetic__shine {
  position: absolute;
  top: 0;
  bottom: 0;
  left: 0;
  width: 35%;
  background: rgba(255, 255, 255, 0.55);
  animation: cosmetic-shine 2.6s ease-in-out infinite;
}

.cosmetic__text {
  padding: 0 4px;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.cosmetic__name {
  font-weight: 800;
  font-size: 14px;
}

.cosmetic__sub {
  font-size: 12px;
  line-height: 1.3;
  color: var(--cm-muted);
}

@keyframes cosmetic-bar {
  0%,
  100% {
    transform: scaleY(0.25);
  }
  50% {
    transform: scaleY(1);
  }
}

@keyframes cosmetic-tip {
  0%,
  30% {
    transform: rotate(0);
  }
  55%,
  85% {
    transform: rotate(88deg) translateY(4px);
  }
  100% {
    transform: rotate(0);
  }
}

@keyframes cosmetic-star {
  0%,
  50% {
    transform: scale(0);
    opacity: 0;
  }
  65% {
    transform: scale(1.2);
    opacity: 1;
  }
  85% {
    opacity: 1;
  }
  100% {
    transform: scale(0.6);
    opacity: 0;
  }
}

@keyframes cosmetic-bob {
  0%,
  100% {
    transform: translateY(0);
  }
  50% {
    transform: translateY(-6px);
  }
}

@keyframes cosmetic-shine {
  0% {
    transform: translateX(-140%) skewX(-20deg);
  }
  60%,
  100% {
    transform: translateX(260%) skewX(-20deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .cosmetic__bar,
  .cosmetic__king,
  .cosmetic__star,
  .cosmetic__prof,
  .cosmetic__shine {
    animation: none;
  }
}
</style>
