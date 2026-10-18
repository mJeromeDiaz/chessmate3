<template>
  <div
    class="carousel"
    data-testid="shop-carousel"
    @pointerdown="down"
    @pointerup="up"
    @mouseenter="hover = true"
    @mouseleave="hover = false"
  >
    <div class="carousel__rain" aria-hidden="true">
      <span
        v-for="(drop, i) in DROPS"
        :key="i"
        class="carousel__drop"
        :style="{
          left: `${drop.x}%`,
          opacity: drop.o,
          '--r': `${drop.r}deg`,
          animationDuration: `${drop.d}s`,
          animationDelay: `${drop.dl}s`
        }"
        ><CrystalGem :size="drop.s" spin :delay="i * 0.3"
      /></span>
    </div>
    <div
      class="carousel__track"
      :style="{ transform: `translateX(${-index * 100}%)` }"
    >
      <button
        v-for="(slide, i) in slides"
        :key="slide.id"
        type="button"
        class="carousel__slide"
        :aria-hidden="i !== index"
        :tabindex="i === index ? 0 : -1"
        :data-testid="`shop-slide-${slide.offer.slug}`"
        @click="open(slide)"
      >
        <img
          v-if="slide.picture"
          :src="slide.picture"
          alt=""
          class="carousel__prof"
          :class="{ 'carousel__prof--on': i === index }"
        />
        <span class="carousel__text">
          <span class="carousel__tag"
            >{{ slide.tag
            }}<template v-if="savingOf(slide.offer)">
              · {{ savingOf(slide.offer) }}</template
            ></span
          >
          <span class="carousel__name">{{ slide.offer.name }}</span>
          <span class="carousel__short">{{ slide.offer.description }}</span>
        </span>
        <span class="carousel__price">
          <span v-if="slide.offer.wasPrice" class="carousel__was">{{
            crowns(slide.offer.wasPrice)
          }}</span>
          <CrystalPrice :amount="slide.offer.price" variant="gold" :coin="24" />
        </span>
      </button>
    </div>
    <div class="carousel__dots">
      <button
        v-for="(slide, i) in slides"
        :key="slide.id"
        type="button"
        class="carousel__dot"
        :class="{ 'carousel__dot--on': i === index }"
        :aria-label="slide.offer.name"
        @click="index = i"
      />
    </div>
  </div>
</template>

<script setup>
/**
 * The store's carousel (design "Boutique"): the offers of the moment on a rain of crowns, one
 * every 4 s (paused on hover or while `paused`), by swipe or dots.
 */
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import CrystalPrice from '@/components/shop/CrystalPrice.vue'
import { crowns, savingOf } from '@/utils/shop'

const props = defineProps({
  /** @type {import('vue').PropType<{id: string, tag: string, picture: string, offer: import('@/utils/shop').ShopOffer}[]>} */
  slides: { type: Array, required: true },
  /** A sheet is open over the store. */
  paused: { type: Boolean, default: false }
})

const emit = defineEmits({
  /** @param {import('@/utils/shop').ShopOffer} offer */
  open: offer => !!offer
})

const SLIDE_MS = 4200
const DROPS = Array.from({ length: 14 }, (_, i) => ({
  x: (i * 53 + 11) % 96,
  s: 10 + ((i * 7) % 16),
  d: 3.2 + ((i * 13) % 10) / 4,
  dl: -((i * 37) % 60) / 10,
  r: (i % 2 ? 1 : -1) * (120 + ((i * 29) % 200)),
  o: 0.35 + ((i * 11) % 5) / 10
}))

const index = ref(0)
const hover = ref(false)
/** @type {number|null} */
let startX = null
let swiped = false
/** @type {ReturnType<typeof setInterval>|undefined} */
let timer

watch(
  () => props.slides.length,
  n => {
    if (index.value >= n) index.value = 0
  }
)

/** @param {PointerEvent} event */
function down(event) {
  startX = event.clientX
  swiped = false
}

/** @param {PointerEvent} event */
function up(event) {
  const dx = event.clientX - (startX ?? event.clientX)
  const n = props.slides.length
  if (Math.abs(dx) > 40 && n > 1) {
    swiped = true
    index.value = (index.value + (dx < 0 ? 1 : -1) + n) % n
  }
}

/** @param {{offer: import('@/utils/shop').ShopOffer}} slide */
function open(slide) {
  if (swiped) {
    swiped = false
    return
  }
  emit('open', slide.offer)
}

onMounted(() => {
  timer = setInterval(() => {
    if (!hover.value && !props.paused && props.slides.length > 1)
      index.value = (index.value + 1) % props.slides.length
  }, SLIDE_MS)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<style scoped lang="scss">
.carousel {
  position: relative;
  flex: none;
  height: 190px;
  overflow: hidden;
  border-radius: 24px;
  background: #1b1530;
  touch-action: pan-y;

  @media (min-width: $breakpoint-md-min) {
    height: 240px;
  }
}

.carousel__rain {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.carousel__drop {
  position: absolute;
  top: 0;
  animation: carousel-fall linear infinite;
}

.carousel__track {
  position: absolute;
  inset: 0;
  display: flex;
  transition: transform 0.6s cubic-bezier(0.65, 0, 0.25, 1);
}

.carousel__slide {
  position: relative;
  flex: 0 0 100%;
  border: none;
  background: transparent;
  text-align: left;
  cursor: pointer;
  padding: 16px 18px 30px;
  color: #fff;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  font-family: inherit;

  @media (min-width: $breakpoint-md-min) {
    padding: 28px 36px 40px;
  }
}

.carousel__prof {
  position: absolute;
  right: -6px;
  bottom: -12px;
  height: 172px;
  pointer-events: none;
  transform: translateY(40px) scale(0.9);
  transition: transform 0.6s cubic-bezier(0.34, 1.6, 0.64, 1);

  &--on {
    transform: none;
  }

  @media (min-width: $breakpoint-md-min) {
    right: 48px;
    height: 230px;
  }
}

.carousel__text {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 6px;
  max-width: 205px;

  @media (min-width: $breakpoint-md-min) {
    max-width: 420px;
  }
}

.carousel__tag {
  align-self: flex-start;
  height: 22px;
  padding: 0 9px;
  border-radius: 999px;
  background: #c6f432;
  color: #1b1530;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.04em;
  display: flex;
  align-items: center;
}

.carousel__name {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 23px;
  line-height: 1.05;

  @media (min-width: $breakpoint-md-min) {
    font-size: 32px;
  }
}

.carousel__short {
  font-size: 12.5px;
  line-height: 1.35;
  color: #dcd8e8;

  @media (min-width: $breakpoint-md-min) {
    font-size: 14px;
  }
}

.carousel__price {
  position: relative;
  display: flex;
  align-items: center;
  gap: 8px;
}

.carousel__was {
  font-size: 13px;
  color: #b4afc6;
  text-decoration: line-through;
}

.carousel__dots {
  position: absolute;
  left: 18px;
  bottom: 11px;
  z-index: 2;
  display: flex;
  gap: 6px;

  @media (min-width: $breakpoint-md-min) {
    left: 36px;
    bottom: 16px;
  }
}

.carousel__dot {
  width: 7px;
  height: 7px;
  padding: 0;
  border: none;
  border-radius: 7px;
  cursor: pointer;
  background: rgba(255, 255, 255, 0.35);
  transition:
    width 0.4s cubic-bezier(0.34, 1.6, 0.64, 1),
    background 0.3s;

  &--on {
    width: 22px;
    background: #c6f432;
  }
}

@keyframes carousel-fall {
  0% {
    transform: translateY(-60px) rotate(0);
  }
  100% {
    transform: translateY(300px) rotate(var(--r));
  }
}

@media (prefers-reduced-motion: reduce) {
  .carousel__drop {
    display: none;
  }
}
</style>
