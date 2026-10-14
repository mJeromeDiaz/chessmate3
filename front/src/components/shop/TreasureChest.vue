<template>
  <div
    class="chest-screen"
    role="dialog"
    aria-modal="true"
    aria-label="Récompense de session"
    data-testid="treasure-chest"
    :style="{ background: stage.bg, color: stage.ink }"
    @keydown.esc="close"
  >
    <div
      class="chest-screen__glow"
      :style="{
        background: `radial-gradient(circle, ${stage.glow}, transparent 62%)`
      }"
    />
    <div
      class="chest-screen__rays"
      :class="{ 'chest-screen__rays--on': open }"
    />
    <div class="chest-screen__sparkles" aria-hidden="true">
      <span
        v-for="(s, i) in sparkles"
        :key="i"
        class="chest-screen__sparkle"
        :style="s"
        >✦</span
      >
    </div>
    <canvas
      v-if="open && !reduced"
      ref="canvas"
      class="chest-screen__burst"
      aria-hidden="true"
    />

    <div class="chest-screen__col">
      <header class="chest-screen__top">
        <button
          ref="closeButton"
          type="button"
          class="chest-screen__close"
          :style="{ color: stage.ink }"
          aria-label="Fermer"
          data-testid="chest-close"
          @click="close"
        >
          ✕
        </button>
        <div v-if="wallet !== null" class="chest-screen__wallet">
          <CrownCoin :size="30" spin /><span data-testid="chest-wallet">{{
            crowns(wallet)
          }}</span>
        </div>
      </header>

      <div class="chest-screen__head">
        <span class="chest-screen__kicker">{{ stage.kicker }}</span>
        <div :key="`t${hits}`" class="chest-screen__title">
          <span :style="{ color: stage.ink }">{{ stage.title[0] }}</span>
          <span :style="{ color: stage.accent }">{{ stage.title[1] }}</span>
        </div>
      </div>

      <div class="chest-screen__stage">
        <button
          ref="chestButton"
          type="button"
          class="chest"
          :class="{ 'chest--open': open }"
          :disabled="open"
          aria-label="Ouvrir le coffre"
          data-testid="chest-hit"
          @click="hit"
        >
          <div class="chest__shadow" />
          <template v-if="open">
            <div class="chest__beam" />
            <div
              v-for="i in 3"
              :key="`ring${i}`"
              class="chest__ring"
              :style="{ animationDelay: `${0.35 + (i - 1) * 0.25}s` }"
            />
          </template>
          <div :key="`w${hits}`" class="chest__body" :class="wrapClass">
            <div v-if="open" class="chest__back">
              <div class="chest__back-face">
                <span
                  v-for="x in [36, 164]"
                  :key="x"
                  class="chest__band"
                  :style="{ left: `${x}px`, top: 0, bottom: '14px' }"
                  ><span class="chest__rivet" style="top: 14px" /><span
                    class="chest__rivet"
                    style="top: calc(100% - 20px)"
                /></span>
              </div>
            </div>
            <div v-if="open" class="chest__gold" />
            <div v-if="open" class="chest__pile">
              <span
                v-for="(c, i) in PILE"
                :key="i"
                class="chest__pile-coin"
                :style="{
                  left: `${c[0] - c[2] / 2}px`,
                  top: `${c[1]}px`,
                  animationDelay: `${0.4 + i * 0.04}s`
                }"
                ><CrownCoin :size="c[2]" :spin="i % 3 === 0" :delay="i * 0.2"
              /></span>
            </div>
            <div class="chest__front">
              <span
                v-for="x in [26, 154]"
                :key="x"
                class="chest__band"
                :style="{ left: `${x}px`, top: 0, bottom: 0 }"
                ><span class="chest__rivet" style="top: 14px" /><span
                  class="chest__rivet"
                  style="top: calc(100% - 20px)"
              /></span>
              <span class="chest__trim" />
            </div>
            <span class="chest__foot chest__foot--left" />
            <span class="chest__foot chest__foot--right" />
            <div
              v-if="!open"
              class="chest__crack"
              :style="{
                opacity: hits >= 1 ? 1 : 0,
                boxShadow: `0 0 ${lit * 14 + lit2 * 22}px ${lit * 4 + lit2 * 9}px rgba(255,230,128,${0.35 + lit2 * 0.5})`
              }"
            />
            <div class="chest__lid" :class="{ 'chest__lid--off': open }">
              <div class="chest__lid-face">
                <span
                  v-for="x in [32, 160]"
                  :key="x"
                  class="chest__band"
                  :style="{ left: `${x}px`, top: 0, bottom: 0 }"
                  ><span class="chest__rivet" style="top: 14px" /><span
                    class="chest__rivet"
                    style="top: calc(100% - 20px)"
                /></span>
                <span class="chest__lid-trim" />
              </div>
            </div>
            <div
              class="chest__lock"
              :class="{ 'chest__lock--off': open }"
              :style="{
                boxShadow: `0 0 0 5px #1B1530, inset 0 -6px 0 #C78F00, 0 0 ${lit * 12 + lit2 * 20}px ${lit * 3 + lit2 * 6}px rgba(255,230,128,.7)`
              }"
            >
              <span class="chest__lock-king">♚︎</span>
              <span class="chest__keyhole" />
            </div>
          </div>
        </button>
      </div>

      <div class="chest-screen__pips" aria-hidden="true">
        <span
          v-for="i in CHEST_HITS"
          :key="i"
          class="chest-screen__pip"
          :style="pipStyle(i - 1)"
        />
      </div>

      <div class="chest-screen__bottom">
        <template v-if="open">
          <div class="chest-reward" data-testid="chest-reward">
            <CrownCoin :size="54" spin />
            <div class="chest-reward__text">
              <div class="chest-reward__amount" data-testid="chest-amount">
                +{{ crowns(shown) }}
              </div>
              <div class="chest-reward__label">
                couronnes ajoutées à ta bourse
              </div>
              <div class="chest-reward__note">{{ note }}</div>
            </div>
          </div>
          <button
            type="button"
            class="chest-screen__action chest-screen__action--main"
            data-testid="chest-results"
            @click="emit('results')"
          >
            {{ resultsLabel }}
          </button>
          <button
            type="button"
            class="chest-screen__action"
            data-testid="chest-shop"
            @click="toShop"
          >
            Dépenser à la boutique
          </button>
        </template>
        <div
          v-else
          class="chest-screen__hint"
          :style="{ color: stage.ink }"
          aria-live="polite"
        >
          {{ stage.hint }}
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * The chest of a completed session (design "Coffre", docs/SHOP.md, § 4 bis): three taps open it,
 * the crowns rain out and the wallet counts them up. The crowns were credited when the session
 * completed; the chest is shown once (opened, or closed unopened: seen either way). The first
 * background follows the amount, the last title the tier; "Voir mes résultats" goes back to the
 * run's result. Reduced motion: the chest open at once.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import CrownCoin from '@/components/shop/CrownCoin.vue'
import { useShopStore } from '@/stores/shop'
import { prefersReducedMotion } from '@/utils/runResult'
import { crowns, tweenAt } from '@/utils/shop'
import { CHEST_HITS, chestStages, rewardNote } from '@/utils/shopReward'

const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/shopReward').SessionReward>} */
  reward: { type: Object, required: true },
  /** The button that leaves the chest for the results. */
  resultsLabel: { type: String, default: 'Voir mes résultats' }
})

const emit = defineEmits({
  /** Back to the results. */
  results: () => true,
  /** Closed (✕, Escape), open or not. */
  close: () => true,
  /** Off to the store (the page changes). */
  shop: () => true
})

const router = useRouter()
const shop = useShopStore()

const CHESS = "'Segoe UI Symbol','DejaVu Sans',serif"
/** [x, y, size] of the coins in the open chest (design). */
const PILE = [
  [-84, 22, 24],
  [-62, 14, 30],
  [-30, 4, 34],
  [4, -4, 38],
  [38, 6, 32],
  [66, 14, 30],
  [86, 22, 24],
  [-46, 26, 28],
  [20, 24, 28],
  [-10, 20, 30],
  [52, 26, 26]
]

const reduced = prefersReducedMotion()
const hits = ref(reduced ? CHEST_HITS : 0)
const open = computed(() => hits.value >= CHEST_HITS)
const shown = ref(reduced ? props.reward.crowns : 0)
/** The balance shown, null until known. */
const wallet = ref(/** @type {number|null} */ (null))
/** The balance before this reward. */
let base = 0

const stages = computed(() => chestStages(props.reward, shop.state?.earning))
const stage = computed(() => stages.value[Math.min(hits.value, CHEST_HITS)])
const lit = computed(() => (hits.value >= 1 ? 1 : 0))
const lit2 = computed(() => (hits.value >= 2 ? 1 : 0))
const note = computed(() => rewardNote(props.reward))

const wrapClass = computed(() => {
  if (open.value) return 'chest__body--open'
  return hits.value === 0 ? 'chest__body--idle' : `chest__body--hit${hits.value}`
})

const sparkles = computed(() => {
  const level = Math.min(hits.value, CHEST_HITS)
  const n = [0, 5, 10, 18][level]
  return Array.from({ length: n }, (_, i) => ({
    left: `${((i * 67 + 23) % 92) + 2}%`,
    top: `${12 + ((i * 101) % 70)}%`,
    fontSize: `${10 + ((i * 7) % 14)}px`,
    color: level === CHEST_HITS ? '#fff' : '#FFD43B',
    animationDuration: `${1.4 + (i % 4) * 0.3}s`,
    animationDelay: `${(i * 0.17) % 1.4}s`
  }))
})

/**
 * @param {number} i
 */
function pipStyle(i) {
  const done = i < hits.value
  return {
    width: done ? '34px' : '12px',
    background: done
      ? open.value
        ? '#1B1530'
        : '#FFD43B'
      : 'rgba(255,255,255,.25)',
    boxShadow: `0 0 0 2px ${open.value ? '#1B1530' : 'rgba(255,255,255,.4)'}`
  }
}

/** @type {ReturnType<typeof setInterval>|undefined} */
let tween
/** @type {ReturnType<typeof setTimeout>|undefined} */
let countLater
/** @type {number|undefined} */
let raf
const canvas = ref(/** @type {HTMLCanvasElement|null} */ (null))
const chestButton = ref(/** @type {HTMLButtonElement|null} */ (null))
const closeButton = ref(/** @type {HTMLButtonElement|null} */ (null))

function hit() {
  if (open.value) return
  hits.value += 1
  if (!open.value) return
  markOpened()
  nextTick(burst)
  countLater = setTimeout(() => {
    let f = 0
    tween = setInterval(() => {
      f += 1
      const p = Math.min(1, f / 40)
      shown.value = tweenAt(0, props.reward.crowns, p)
      if (wallet.value !== null)
        wallet.value = tweenAt(base, base + props.reward.crowns, p)
      if (p >= 1) clearInterval(tween)
    }, 30)
  }, 1200)
}

/** The chest seen: the server stops showing it; the menus get the new balance. */
function markOpened() {
  shop.openChest(props.reward.id).catch(() => {})
}

/** Closed before or after opening: seen all the same. */
function close() {
  markOpened()
  emit('close')
}

function toShop() {
  emit('shop')
  router.push('/boutique')
}

/** Coins and confetti thrown from the chest (design canvas). */
function burst() {
  const cv = canvas.value
  if (!cv) return
  const W = cv.offsetWidth
  const H = cv.offsetHeight
  const dpr = globalThis.devicePixelRatio || 1
  cv.width = W * dpr
  cv.height = H * dpr
  const ctx = cv.getContext('2d')
  if (!ctx) return
  ctx.scale(dpr, dpr)
  const rect = chestButton.value?.getBoundingClientRect()
  const ox = rect ? rect.left + rect.width / 2 : W / 2
  const oy = rect ? rect.top + rect.height * 0.55 : H / 2
  const P = []
  let f = 0
  const spawn = n => {
    for (let i = 0; i < n; i++) {
      const a = -Math.PI / 2 + (Math.random() - 0.5) * 1.6
      const v = 9 + Math.random() * 9
      P.push({
        x: ox + (Math.random() - 0.5) * 60,
        y: oy,
        vx: Math.cos(a) * v,
        vy: Math.sin(a) * v,
        s: 12 + Math.random() * 12,
        ph: Math.random() * 6,
        conf: Math.random() < 0.35,
        c: ['#1B1530', '#C6F432', '#FF6FAE', '#8B6BFF', '#fff'][i % 5]
      })
    }
  }
  const coin = (p, sc) => {
    const w = p.s * Math.abs(sc)
    const h = p.s
    ctx.fillStyle = '#C78F00'
    ctx.beginPath()
    ctx.ellipse(0, 2, w, h, 0, 0, 7)
    ctx.fill()
    const g = ctx.createRadialGradient(-w * 0.3, -h * 0.4, 1, 0, 0, h)
    g.addColorStop(0, '#FFF6C2')
    g.addColorStop(0.45, '#FFD43B')
    g.addColorStop(1, '#E7A900')
    ctx.fillStyle = g
    ctx.beginPath()
    ctx.ellipse(0, 0, w, h, 0, 0, 7)
    ctx.fill()
    if (Math.abs(sc) > 0.4) {
      ctx.fillStyle = '#8A6A00'
      ctx.font = `${h * 1.1}px ${CHESS}`
      ctx.textAlign = 'center'
      ctx.textBaseline = 'middle'
      ctx.save()
      ctx.scale(Math.abs(sc), 1)
      ctx.fillText('♔', 0, 1)
      ctx.restore()
    }
  }
  const tick = () => {
    f++
    ctx.clearRect(0, 0, W, H)
    if (f > 22 && f < 70 && f % 3 === 0) spawn(5)
    for (const p of P) {
      p.vy += 0.42
      p.vx *= 0.99
      p.x += p.vx
      p.y += p.vy
      if (p.y > H - 40 && p.vy > 0) {
        p.y = H - 40
        p.vy *= -0.35
        p.vx *= 0.7
      }
      ctx.save()
      ctx.translate(p.x, p.y)
      if (p.conf) {
        ctx.rotate(f / 8 + p.ph)
        ctx.fillStyle = p.c
        ctx.fillRect(
          -p.s / 3,
          -p.s / 6,
          p.s * 0.66,
          p.s * 0.33 * Math.abs(Math.cos(f / 5 + p.ph)) + 1
        )
      } else coin(p, Math.cos(f / 6 + p.ph))
      ctx.restore()
    }
    if (f < 260) raf = requestAnimationFrame(tick)
    else ctx.clearRect(0, 0, W, H)
  }
  raf = requestAnimationFrame(tick)
}

onMounted(async () => {
  ;(reduced ? closeButton.value : chestButton.value)?.focus()
  if (reduced) markOpened()
  const state = await shop.load().catch(() => null)
  if (!state) return
  base = Math.max(0, state.balance - props.reward.crowns)
  // Opened already (reduced motion), or a reload raced the tween: the final balance.
  wallet.value = open.value && shown.value >= props.reward.crowns ? state.balance : base
})

onBeforeUnmount(() => {
  clearInterval(tween)
  clearTimeout(countLater)
  if (raf !== undefined) cancelAnimationFrame(raf)
})
</script>

<style scoped lang="scss">
$ink: #1b1530;
$gold: linear-gradient(180deg, #fff1a8, #ffd43b 45%, #e7a900);
$grain: repeating-linear-gradient(
  90deg,
  rgba(255, 255, 255, 0.04) 0 3px,
  transparent 3px 17px
);

.chest-screen {
  position: fixed;
  inset: 0;
  // Above Quasar's dialogs (6000): the "end the run?" confirmation may still be fading out.
  z-index: 6100;
  overflow: hidden;
  transition:
    background 0.5s ease,
    color 0.4s;
  font-family: 'DM Sans', system-ui, sans-serif;
}

.chest-screen__glow {
  position: absolute;
  left: 50%;
  top: 42%;
  width: 620px;
  height: 620px;
  margin: -310px 0 0 -310px;
  border-radius: 50%;
  transition: background 0.5s ease;
  pointer-events: none;
}

.chest-screen__rays {
  position: absolute;
  left: 50%;
  top: 46%;
  width: 900px;
  height: 900px;
  margin: -450px 0 0 -450px;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.6s 0.3s;
  background: repeating-conic-gradient(
    rgba(255, 255, 255, 0.4) 0 10deg,
    transparent 10deg 24deg
  );
  mask-image: radial-gradient(circle, #000 20%, transparent 60%);
  animation: cf-ray 14s linear infinite;

  &--on {
    opacity: 1;
  }
}

.chest-screen__sparkles {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.chest-screen__sparkle {
  position: absolute;
  animation: cf-twinkle ease-in-out infinite both;
}

.chest-screen__burst {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  z-index: 3;
}

.chest-screen__col {
  position: relative;
  z-index: 2;
  height: 100%;
  max-width: 480px;
  margin: 0 auto;
  padding: 12px 16px 28px;
  display: flex;
  flex-direction: column;
}

.chest-screen__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 40px;
}

.chest-screen__close {
  width: 40px;
  height: 40px;
  border: none;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.16);
  font-size: 18px;
  font-weight: 800;
  cursor: pointer;
}

.chest-screen__wallet {
  height: 40px;
  padding: 0 14px 0 5px;
  border-radius: 14px;
  background: $ink;
  color: #ffd43b;
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 800;
  font-size: 16px;
  font-variant-numeric: tabular-nums;
}

.chest-screen__head {
  margin-top: 18px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  text-align: center;
}

.chest-screen__kicker {
  height: 26px;
  padding: 0 11px;
  border-radius: 999px;
  background: $ink;
  color: #c6f432;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.06em;
  display: flex;
  align-items: center;
}

.chest-screen__title {
  display: flex;
  flex-direction: column;
  align-items: center;
  animation: cf-pop 0.5s cubic-bezier(0.34, 1.8, 0.64, 1) both;

  span {
    font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
    font-weight: 800;
    font-size: 40px;
    line-height: 1;
    letter-spacing: -0.02em;
  }
}

.chest-screen__stage {
  flex: 1;
  min-height: 300px;
  display: flex;
  justify-content: center;
  align-items: flex-end;
}

.chest-screen__pips {
  margin-top: 8px;
  display: flex;
  justify-content: center;
  gap: 10px;
}

.chest-screen__pip {
  height: 12px;
  border-radius: 12px;
  transition:
    width 0.4s cubic-bezier(0.34, 1.6, 0.64, 1),
    background 0.3s;
}

.chest-screen__bottom {
  margin-top: 20px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 56px;
  position: relative;
  z-index: 4;
}

.chest-screen__hint {
  height: 56px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  font-weight: 700;
  opacity: 0.8;
}

.chest-screen__action {
  height: 52px;
  border-radius: 18px;
  border: 2px solid $ink;
  background: #fff;
  color: $ink;
  font-weight: 800;
  font-size: 16px;
  cursor: pointer;
  box-shadow: 0 4px 0 #4c475e;
  animation: cf-up 0.5s 1.6s both;

  &--main {
    background: $ink;
    color: #fff;
  }
}

.chest-reward {
  display: flex;
  align-items: center;
  gap: 14px;
  background: #fff;
  border-radius: 22px;
  padding: 14px 16px;
  box-shadow: 0 6px 0 rgba(27, 21, 48, 0.15);
  animation: cf-pop 0.6s cubic-bezier(0.34, 1.8, 0.64, 1) 1.1s both;
}

.chest-reward__text {
  flex: 1;
  min-width: 0;
}

.chest-reward__amount {
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: 34px;
  line-height: 1;
  font-variant-numeric: tabular-nums;
  color: $ink;
}

.chest-reward__label {
  font-size: 13px;
  font-weight: 700;
  color: #8a6a00;
  margin-top: 3px;
}

.chest-reward__note {
  font-size: 12px;
  font-weight: 600;
  color: #6e6a80;
  margin-top: 2px;
}

/* The chest (design: 240 × 300, the body 250 high). */
.chest {
  position: relative;
  width: 240px;
  height: 300px;
  border: none;
  background: none;
  padding: 0;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;

  &--open {
    cursor: default;
  }

  &:focus-visible {
    outline: 3px solid #c6f432;
    outline-offset: 6px;
    border-radius: 24px;
  }
}

.chest__shadow {
  position: absolute;
  left: 20px;
  right: 20px;
  bottom: -8px;
  height: 24px;
  border-radius: 50%;
  background: rgba(27, 21, 48, 0.35);
  filter: blur(4px);
}

.chest__beam {
  position: absolute;
  left: 60px;
  right: 60px;
  bottom: 150px;
  height: 400px;
  transform-origin: 50% 100%;
  background: linear-gradient(0deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0));
  clip-path: polygon(25% 100%, 75% 100%, 100% 0, 0 0);
  animation: cf-beam 0.5s ease-out 0.35s both;
}

.chest__ring {
  position: absolute;
  left: 60px;
  top: 110px;
  width: 120px;
  height: 120px;
  border-radius: 50%;
  border: 5px solid #fff;
  animation: cf-ring 1.2s ease-out both;
}

.chest__body {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 250px;
  transform-origin: 50% 100%;

  &--idle {
    animation: cf-idle 2.6s ease-in-out infinite;
  }

  &--hit1 {
    animation:
      cf-hit1 0.6s cubic-bezier(0.34, 1.5, 0.64, 1) both,
      cf-rumble 0.12s linear 0.6s infinite;
  }

  &--hit2 {
    animation:
      cf-hit2 0.6s cubic-bezier(0.34, 1.5, 0.64, 1) both,
      cf-rumble 0.12s linear 0.6s infinite;
  }

  &--open {
    animation: cf-hit3 0.7s cubic-bezier(0.34, 1.5, 0.64, 1) both;
  }
}

.chest__band {
  position: absolute;
  width: 24px;
  background: $gold;
  box-shadow: inset 0 0 0 2px rgba(138, 106, 0, 0.45);
}

.chest__rivet {
  position: absolute;
  left: 8px;
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #8a6a00;
}

.chest__back {
  position: absolute;
  left: 8px;
  right: 8px;
  top: 44px;
  height: 78px;
  z-index: 0;
  transform-origin: 50% 100%;
  animation: cf-flip 0.55s cubic-bezier(0.34, 1.8, 0.64, 1) 0.2s both;
}

.chest__back-face {
  position: absolute;
  inset: 0;
  border-radius: 50% 50% 8px 8px / 70% 70% 8px 8px;
  background:
    $grain,
    linear-gradient(180deg, #5e3010, #3a1c06);
  box-shadow:
    0 0 0 5px $ink,
    inset 0 -14px 0 #e7a900;
  overflow: hidden;
}

.chest__gold {
  position: absolute;
  left: 14px;
  right: 14px;
  top: 98px;
  height: 40px;
  z-index: 1;
  border-radius: 50%;
  background: radial-gradient(ellipse, #fff6c2, #ffd43b 60%, #c78f00);
  box-shadow: 0 0 44px 18px rgba(255, 236, 150, 0.85);
  animation: cf-in 0.3s 0.4s both;
}

.chest__pile {
  position: absolute;
  left: 120px;
  top: 92px;
  z-index: 2;
}

.chest__pile-coin {
  position: absolute;
  animation: cf-pop 0.45s cubic-bezier(0.34, 1.8, 0.64, 1) both;
}

.chest__front {
  position: absolute;
  left: 10px;
  right: 10px;
  bottom: 0;
  height: 132px;
  z-index: 3;
  border-radius: 8px 8px 18px 18px;
  background:
    repeating-linear-gradient(180deg, transparent 0 40px, #4a2408 40px 44px),
    $grain,
    linear-gradient(180deg, #a8642c, #7a4116 60%, #5e3010);
  box-shadow:
    0 0 0 5px $ink,
    inset 0 -10px 0 rgba(27, 21, 48, 0.3);
  overflow: hidden;
}

.chest__trim {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  height: 12px;
  background: $gold;
}

.chest__foot {
  position: absolute;
  bottom: -4px;
  width: 30px;
  height: 30px;
  z-index: 3;
  background: $gold;
  box-shadow: 0 0 0 5px $ink;

  &--left {
    left: 4px;
    border-radius: 4px 4px 4px 16px;
  }

  &--right {
    right: 4px;
    border-radius: 4px 4px 16px 4px;
  }
}

.chest__crack {
  position: absolute;
  left: 12px;
  right: 12px;
  top: 116px;
  height: 6px;
  z-index: 4;
  border-radius: 6px;
  background: #fff6c2;
  transition:
    opacity 0.3s,
    box-shadow 0.3s;
}

.chest__lid {
  position: absolute;
  left: 4px;
  right: 4px;
  top: 36px;
  height: 86px;
  z-index: 5;
  transform-origin: 50% 100%;

  &--off {
    animation: cf-lid-off 0.22s ease-in 0.2s both;
  }
}

.chest__lid-face {
  position: absolute;
  inset: 0;
  border-radius: 50% 50% 8px 8px / 72% 72% 8px 8px;
  background:
    $grain,
    linear-gradient(180deg, #c07a3a, #a8642c 45%, #7a4116);
  box-shadow:
    0 0 0 5px $ink,
    inset 0 10px 0 rgba(255, 255, 255, 0.14);
  overflow: hidden;
}

.chest__lid-trim {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 14px;
  background: $gold;
}

.chest__lock {
  position: absolute;
  left: 93px;
  top: 100px;
  width: 54px;
  height: 62px;
  z-index: 6;
  border-radius: 12px 12px 22px 22px;
  background: $gold;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  transition:
    transform 0.45s cubic-bezier(0.5, 0, 0.7, 1),
    opacity 0.45s,
    box-shadow 0.3s;

  &--off {
    transform: translateY(110px) rotate(50deg);
    opacity: 0;
  }
}

.chest__lock-king {
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  font-size: 20px;
  line-height: 1;
  color: #8a6a00;
}

.chest__keyhole {
  width: 8px;
  height: 14px;
  border-radius: 4px 4px 1px 1px;
  background: $ink;
}

/* Short screens: the chest shrinks a little rather than pushing the buttons out. */
@media (max-height: 720px) {
  .chest-screen__title span {
    font-size: 32px;
  }

  .chest-screen__stage {
    min-height: 240px;
  }

  .chest {
    transform: scale(0.8);
    transform-origin: 50% 100%;
  }
}

@keyframes cf-idle {
  0%,
  100% {
    transform: translateY(0) rotate(0);
  }
  50% {
    transform: translateY(-6px) rotate(-1deg);
  }
}
@keyframes cf-hit1 {
  0% {
    transform: scale(1);
  }
  20% {
    transform: scale(0.88, 1.1) rotate(-6deg);
  }
  45% {
    transform: scale(1.08, 0.94) rotate(5deg);
  }
  70% {
    transform: rotate(-3deg);
  }
  100% {
    transform: none;
  }
}
@keyframes cf-hit2 {
  0% {
    transform: scale(1);
  }
  15% {
    transform: scale(0.84, 1.14) translateY(-30px) rotate(9deg);
  }
  35% {
    transform: scale(1.12, 0.9) rotate(-9deg);
  }
  55% {
    transform: translateX(-10px) rotate(6deg);
  }
  75% {
    transform: translateX(8px) rotate(-4deg);
  }
  100% {
    transform: none;
  }
}
@keyframes cf-hit3 {
  0% {
    transform: scale(1);
  }
  20% {
    transform: scale(0.8, 1.2) translateY(-50px);
  }
  45% {
    transform: scale(1.18, 0.86);
  }
  65% {
    transform: scale(0.96, 1.04);
  }
  100% {
    transform: none;
  }
}
@keyframes cf-rumble {
  0%,
  100% {
    transform: translate(0, 0);
  }
  25% {
    transform: translate(-2px, 1px);
  }
  50% {
    transform: translate(2px, -1px);
  }
  75% {
    transform: translate(-1px, -2px);
  }
}
@keyframes cf-lid-off {
  to {
    transform: scaleY(0);
  }
}
@keyframes cf-flip {
  from {
    transform: scaleY(0);
  }
  to {
    transform: scaleY(1);
  }
}
@keyframes cf-beam {
  from {
    transform: scaleY(0);
    opacity: 0;
  }
  to {
    transform: scaleY(1);
    opacity: 1;
  }
}
@keyframes cf-ray {
  to {
    transform: rotate(360deg);
  }
}
@keyframes cf-ring {
  from {
    transform: scale(0.4);
    opacity: 0.9;
  }
  to {
    transform: scale(2.4);
    opacity: 0;
  }
}
@keyframes cf-pop {
  0% {
    transform: scale(0) rotate(-12deg);
  }
  60% {
    transform: scale(1.15) rotate(4deg);
  }
  100% {
    transform: scale(1) rotate(0);
  }
}
@keyframes cf-up {
  from {
    transform: translateY(24px);
    opacity: 0;
  }
  to {
    transform: none;
    opacity: 1;
  }
}
@keyframes cf-twinkle {
  0%,
  100% {
    transform: scale(0.3);
    opacity: 0;
  }
  50% {
    transform: scale(1);
    opacity: 1;
  }
}
@keyframes cf-in {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

@media (prefers-reduced-motion: reduce) {
  .chest-screen *,
  .chest-screen__rays {
    animation: none !important;
    transition: none !important;
  }

  .chest__lid--off {
    transform: scaleY(0);
  }
}
</style>
