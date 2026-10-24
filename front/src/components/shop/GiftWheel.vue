<template>
  <q-dialog
    :model-value="true"
    maximized
    transition-show="fade"
    transition-hide="fade"
    @update:model-value="!$event && leave()"
  >
    <div
      class="wheel-screen"
      role="dialog"
      aria-modal="true"
      :aria-label="$t('shop.wheel.label')"
      data-testid="gift-wheel"
      :style="{ background: pageBg }"
    >
      <header class="wheel-screen__top">
        <button
          type="button"
          class="wheel-screen__close"
          :aria-label="$t('common.actions.close')"
          data-testid="wheel-close"
          @click="leave"
        >
          ✕
        </button>
        <div class="wheel-screen__wallet-wrap">
          <div
            class="wheel-screen__wallet"
            :class="{ 'wheel-screen__wallet--bump': counting }"
          >
            <CrystalGem :size="26" /><span data-testid="wheel-wallet">{{
              crowns(shown)
            }}</span>
          </div>
          <span
            v-if="floatKey"
            :key="`f${floatKey}`"
            class="wheel-screen__float"
            aria-hidden="true"
            >+{{ floatN }}</span
          >
        </div>
      </header>

      <div class="wheel-screen__col">
        <div class="wheel-screen__head">
          <span class="wheel-screen__title">{{ head[0] }}</span>
          <span
            class="wheel-screen__sub"
            :class="{ 'wheel-screen__sub--warm': warm }"
            >{{ head[1] }}</span
          >
        </div>

        <div class="wheel-stage">
          <!-- The wheel -->
          <div
            class="wheel"
            :class="{ 'wheel--away': stage !== 'wheel' }"
            :aria-hidden="stage !== 'wheel'"
          >
            <div class="wheel__rim" />
            <div
              class="wheel__disc"
              :style="{
                transform: `rotate(${rot}deg)`,
                background: gradient
              }"
            >
              <div
                v-for="(segment, i) in SEGMENTS"
                :key="i"
                class="wheel__slot"
                :style="{
                  transform: `rotate(${i * SEGMENT_DEG + SEGMENT_DEG / 2}deg)`
                }"
              >
                <div class="wheel__label">
                  <template v-if="segment.prize === 'lose'">
                    <svg width="36" height="36" viewBox="0 0 24 24">
                      <path
                        d="M12 2.5c-4.7 0-8 3.2-8 7.6 0 2.6 1.2 4.4 3 5.4V19c0 .8.7 1.5 1.5 1.5h7c.8 0 1.5-.7 1.5-1.5v-3.5c1.8-1 3-2.8 3-5.4 0-4.4-3.3-7.6-8-7.6z"
                        fill="#E4E0EE"
                      />
                      <circle cx="8.8" cy="11" r="2" fill="#0B0910" />
                      <circle cx="15.2" cy="11" r="2" fill="#0B0910" />
                      <path d="M12 13.5l-1.2 2.2h2.4z" fill="#0B0910" />
                      <path
                        d="M10 17.5v3M12 17.5v3M14 17.5v3"
                        stroke="#0B0910"
                        stroke-width="1"
                      />
                    </svg>
                    <span class="wheel__word">{{
                      $t('shop.wheel.segments.lose')
                    }}</span>
                  </template>
                  <template v-else-if="segment.prize === 'gift'">
                    <svg width="36" height="36" viewBox="0 0 24 24">
                      <rect x="3" y="9" width="18" height="4" rx="1" fill="#fff" />
                      <rect
                        x="4.5"
                        y="13"
                        width="15"
                        height="8"
                        rx="1"
                        fill="#fff"
                      />
                      <rect
                        x="10.8"
                        y="9"
                        width="2.4"
                        height="12"
                        fill="#C2541A"
                      />
                      <path
                        d="M12 9c-1-3-4.5-4.5-5.5-2.5S9 9 12 9zm0 0c1-3 4.5-4.5 5.5-2.5S15 9 12 9z"
                        fill="none"
                        stroke="#fff"
                        stroke-width="1.6"
                      />
                    </svg>
                    <span class="wheel__word wheel__word--gift">{{
                      $t('shop.wheel.segments.gift')
                    }}</span>
                  </template>
                  <template v-else>
                    <CrystalGem :size="segment.prize === 'c300' ? 34 : 30" />
                    <span
                      class="wheel__amount"
                      :class="{ 'wheel__amount--jackpot': segment.prize === 'c300' }"
                      >{{ PRIZE_CROWNS[segment.prize] }}</span
                    >
                  </template>
                </div>
              </div>
            </div>
            <div class="wheel__pointer" aria-hidden="true" />
            <button
              type="button"
              class="wheel__hub"
              :disabled="spinning || busy"
              :aria-label="$t('shop.wheel.spinLabel')"
              data-testid="wheel-spin"
              @click="spin"
            >
              {{ hubLabel }}
            </button>
          </div>

          <!-- The three chests of a gift -->
          <div v-if="stage === 'chests'" class="wheel-chests">
            <button
              v-for="(gem, i) in GEMS"
              :key="i"
              type="button"
              class="wheel-chest"
              :class="[
                `wheel-chest--${chestState(i)}`,
                { 'wheel-chest--big': picked === i }
              ]"
              :style="{ animationDelay: `${0.45 + i * 0.12}s` }"
              :aria-label="$t('shop.wheel.chests.chest', { gem: $t(`shop.wheel.gems.${gem.id}`) })"
              :data-testid="`wheel-chest-${i}`"
              @click="pick(i)"
            >
              <div
                :key="`h${picked === i ? hits : 0}`"
                class="wheel-chest__body"
                :class="{ 'wheel-chest__body--hit': picked === i && hits > 0 }"
              >
                <span class="wheel-chest__shadow" />
                <span v-if="picked === i && chestOpen" class="wheel-chest__glow" />
                <span class="wheel-chest__box">
                  <span class="wheel-chest__band wheel-chest__band--left" />
                  <span class="wheel-chest__band wheel-chest__band--right" />
                  <span class="wheel-chest__trim" />
                </span>
                <span
                  v-if="!(picked === i && chestOpen)"
                  class="wheel-chest__crack"
                  :style="crackStyle(i)"
                />
                <span v-if="picked === i && chestOpen" class="wheel-chest__gold" />
                <span
                  class="wheel-chest__lid"
                  :class="{ 'wheel-chest__lid--off': picked === i && chestOpen }"
                >
                  <span class="wheel-chest__band wheel-chest__band--left" />
                  <span class="wheel-chest__band wheel-chest__band--right" />
                  <span class="wheel-chest__lid-trim" />
                </span>
                <span
                  class="wheel-chest__lock"
                  :class="{ 'wheel-chest__lock--off': picked === i && chestOpen }"
                >
                  <span class="wheel-chest__gem" :style="gemStyle(gem, i)" />
                </span>
              </div>
            </button>
          </div>

          <!-- The gift -->
          <div
            v-if="stage === 'gift'"
            class="wheel-gift"
            data-testid="wheel-gift"
            :data-gift="result?.gift"
          >
            <div class="wheel-screen__rays wheel-screen__rays--warm" />
            <div
              class="wheel-gift__glow"
              :class="{ 'wheel-gift__glow--ice': result?.gift === 'freeze' }"
            />
            <span
              v-for="(s, i) in sparkles(10)"
              :key="i"
              class="wheel-screen__sparkle"
              :style="s"
              aria-hidden="true"
              >✦</span
            >
            <img
              v-if="result?.gift === 'costume'"
              class="wheel-gift__albert"
              :src="albert"
              :alt="$t('shop.wheel.costume.alt')"
            />
            <div v-else class="wheel-gift__ice" aria-hidden="true">❄︎</div>
          </div>

          <!-- The crystals won -->
          <div v-if="stage === 'gain' && result" class="wheel-gain" data-testid="wheel-gain">
            <div
              v-if="result.crowns >= 30"
              class="wheel-screen__rays"
              :class="{
                'wheel-screen__rays--big': result.crowns >= 300,
                'wheel-screen__rays--fast': result.crowns >= 300 && counting
              }"
            />
            <div
              class="wheel-gain__glow"
              :class="{ 'wheel-gain__glow--big': result.crowns >= 300 }"
            />
            <span
              v-for="(s, i) in sparkles(result.crowns >= 300 ? 14 : result.crowns >= 30 ? 8 : 4)"
              :key="i"
              class="wheel-screen__sparkle"
              :style="s"
              aria-hidden="true"
              >✦</span
            >
            <div
              class="wheel-gain__pill"
              :class="{ 'wheel-gain__pill--big': result.crowns >= 300 }"
              data-testid="wheel-gain-amount"
            >
              +{{ crowns(result.crowns) }}
            </div>
            <div
              class="wheel-gain__stack"
              :class="{
                'wheel-gain__stack--buzz': counting && result.crowns >= 30,
                'wheel-gain__stack--buzz-fast': counting && result.crowns >= 300
              }"
            >
              <CrystalGem :size="result.crowns >= 300 ? 120 : 100" spin />
              <span :key="shown" class="wheel-gain__count" :class="{ 'wheel-gain__count--tick': counting }">{{
                crowns(shown)
              }}</span>
            </div>
            <span class="wheel-gain__label">{{ $t('shop.wheel.gain.reserve') }}</span>
          </div>
        </div>

        <!-- Under the stage -->
        <div class="wheel-screen__bottom">
          <template v-if="stage === 'wheel'">
            <span class="wheel-screen__hint" aria-live="polite">{{ hint }}</span>
            <template v-if="canDecline">
              <button
                type="button"
                class="wheel-screen__action"
                :disabled="busy || spinning"
                data-testid="wheel-decline"
                @click="decline(false)"
              >
                {{ $t('shop.wheel.decline') }}
              </button>
              <button
                type="button"
                class="wheel-screen__link"
                :disabled="busy || spinning"
                data-testid="wheel-never"
                @click="askNever"
              >
                {{ $t('shop.wheel.never') }}
              </button>
            </template>
            <button
              v-else-if="spun && !spinning"
              type="button"
              class="wheel-screen__action wheel-screen__action--main"
              data-testid="wheel-continue"
              @click="leave"
            >
              {{ $t('shop.wheel.continue') }}
            </button>
          </template>

          <div
            v-else-if="stage === 'gift' && result"
            class="wheel-screen__gift-text"
            data-testid="wheel-gift-text"
          >
            <span
              class="wheel-screen__badge"
              :class="{ 'wheel-screen__badge--ice': result.gift === 'freeze' }"
              >{{ $t(`shop.wheel.${result.gift === 'freeze' ? 'freeze' : 'costume'}.kicker`) }}</span
            >
            <span class="wheel-screen__gift-title">{{
              $t(`shop.wheel.${result.gift === 'freeze' ? 'freeze' : 'costume'}.title`)
            }}</span>
            <span class="wheel-screen__gift-body">{{
              $t(`shop.wheel.${result.gift === 'freeze' ? 'freeze' : 'costume'}.body`)
            }}</span>
          </div>

          <div v-if="ended" class="wheel-screen__actions">
            <button
              type="button"
              class="wheel-screen__action wheel-screen__action--main"
              data-testid="wheel-continue"
              @click="leave"
            >
              {{ $t('shop.wheel.continue') }}
            </button>
            <button
              type="button"
              class="wheel-screen__action"
              :disabled="busy"
              data-testid="wheel-again"
              @click="again"
            >
              {{ againLabel }}
            </button>
          </div>

          <q-banner v-if="error" class="wheel-screen__error" rounded>{{ error }}</q-banner>
        </div>
      </div>

      <!-- The skull -->
      <div
        v-if="loseOpen"
        class="wheel-lose"
        data-testid="wheel-lose"
        @click="loseOpen = false"
      >
        <div class="wheel-lose__card" @click.stop>
          <span class="wheel-lose__kicker">{{ $t('shop.wheel.lose.kicker') }}</span>
          <span class="wheel-lose__title">{{ $t('shop.wheel.lose.title') }}</span>
          <span class="wheel-lose__body">{{ $t('shop.wheel.lose.body') }}</span>
          <div class="wheel-lose__actions">
            <button
              type="button"
              class="wheel-screen__action"
              data-testid="wheel-lose-close"
              @click="loseOpen = false"
            >
              {{ $t('common.actions.close') }}
            </button>
            <button
              type="button"
              class="wheel-screen__action wheel-screen__action--main"
              :disabled="busy"
              data-testid="wheel-again"
              @click="again"
            >
              {{ againLabel }}
            </button>
          </div>
        </div>
      </div>

      <canvas
        v-if="confettiKey && !reduced"
        :key="`c${confettiKey}`"
        ref="canvas"
        class="wheel-screen__confetti"
        aria-hidden="true"
      />

      <PurchaseSheet
        :offer="purchase.sheet.value"
        :balance="shop.state?.balance ?? 0"
        :inventory="shop.state?.inventory ?? {}"
        :busy="purchase.busy.value"
        :error="purchase.error.value"
        @close="purchase.close()"
        @buy="purchase.buy"
      />
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * The gift wheel (design "Roue des cadeaux", docs/SHOP.md, § 4 ter): the day's (after the
 * streak's celebration) or a ticket's (from the store). The server draws the prize and credits
 * it; the wheel only slows down onto one of its segments. Crystals count up into the reserve (the
 * jackpot with confetti), a gift opens one of three chests (3 taps) onto a streak freeze or a
 * costume for Albert, the skull gives nothing. « Relancer » spends a ticket, bought in place when
 * there is none. Before the day's spin: « Pas aujourd'hui » (lost for the day) or « Ne plus me
 * proposer la roue » (the profile's setting). Dark in both themes, as the design; reduced motion:
 * no spin, no confetti, the chest open at the first tap.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useQuasar } from 'quasar'
import CrystalGem from '@/components/shop/CrystalGem.vue'
import PurchaseSheet from '@/components/shop/PurchaseSheet.vue'
import { useAidPurchase } from '@/composables/shop/useAidPurchase'
import { useAuthStore } from '@/stores/auth'
import { useShopStore } from '@/stores/shop'
import { apiErrorMessage } from '@/utils/apiError'
import {
  PRIZE_CROWNS,
  REST_ROTATION,
  SEGMENTS,
  SEGMENT_DEG,
  countUpMs,
  landingRotation,
  segmentOf,
  spinCurve,
  wheelGradient,
  wheelHead
} from '@/utils/giftWheel'
import { profImage } from '@/utils/prof/images'
import { prefersReducedMotion } from '@/utils/runResult'
import { aidOffer, crowns, tweenAt } from '@/utils/shop'
import { t } from '@/i18n'

const props = defineProps({
  /** The day's wheel (its daily reward); null: a ticket's, from the store. */
  dailyRewardId: { type: String, default: null }
})

const emit = defineEmits({
  /** Left: spun, declined or closed. */
  close: () => true
})

const $q = useQuasar()
const shop = useShopStore()
const auth = useAuthStore()
const purchase = useAidPurchase(() => nextTick(spin))

/** The chests' stones (design; the third green rather than yellow, 2026-10-24). */
const GEMS = [
  { id: 'blue', c: '#3D7BFF', hi: '#9EC2FF' },
  { id: 'red', c: '#FF3D5A', hi: '#FFA3B1' },
  { id: 'green', c: '#1FBF6A', hi: '#A6F0C6' }
]
const SPIN_SECONDS = 5
const CHEST_TAPS = 3

const reduced = prefersReducedMotion()
const gradient = wheelGradient()
const albert = profImage('albert-full')

const stage = ref(/** @type {'wheel'|'gain'|'chests'|'gift'} */ ('wheel'))
const rot = ref(REST_ROTATION)
const spinning = ref(false)
const busy = ref(false)
/** A spin done on this screen: the day's wheel is gone, « Relancer » needs a ticket. */
const spun = ref(false)
/** @type {import('vue').Ref<import('@/utils/giftWheel').WheelSpin|null>} */
const result = ref(null)
const loseOpen = ref(false)
const error = ref('')
const picked = ref(/** @type {number|null} */ (null))
const hits = ref(0)
/** The balance shown: counts up after a gain. */
const shown = ref(shop.state?.balance ?? 0)
const counting = ref(false)
const floatKey = ref(0)
const floatN = ref(0)
const confettiKey = ref(0)
const canvas = ref(/** @type {HTMLCanvasElement|null} */ (null))

const chestOpen = computed(() => hits.value >= CHEST_TAPS)
const tickets = computed(() => shop.state?.inventory?.wheel_ticket ?? 0)
const ticketPrice = computed(
  () => aidOffer(shop.state?.offers ?? [], 'wheel_ticket')?.price ?? null
)
/** The day's wheel, not spun yet. */
const daily = computed(() => !!props.dailyRewardId && !spun.value)
const canDecline = computed(() => daily.value && !spinning.value)
/** The prize shown, the buttons under it. */
const ended = computed(
  () =>
    (stage.value === 'gain' && !counting.value && result.value !== null) ||
    stage.value === 'gift'
)
const warm = computed(
  () => stage.value === 'gift' || (stage.value === 'chests' && chestOpen.value)
)

const head = computed(() =>
  wheelHead(stage.value, {
    picked: picked.value,
    hits: hits.value,
    open: chestOpen.value,
    gain: result.value?.crowns,
    gift: result.value?.gift,
    ticket: !props.dailyRewardId
  })
)

const hubLabel = computed(() => {
  if (spinning.value) return '…'
  return daily.value || !spun.value
    ? t('shop.wheel.spin')
    : t('shop.wheel.again')
})

const againLabel = computed(() =>
  tickets.value > 0
    ? t('shop.wheel.againTicket', { n: tickets.value }, tickets.value)
    : ticketPrice.value !== null
      ? t('shop.wheel.againBuy', { price: crowns(ticketPrice.value) })
      : t('shop.wheel.again')
)

const hint = computed(() => {
  if (spinning.value) return t('shop.wheel.hints.spinning')
  if (daily.value || !spun.value) return t('shop.wheel.hints.start')
  return tickets.value > 0
    ? t('shop.wheel.hints.again', { n: tickets.value }, tickets.value)
    : t('shop.wheel.hints.buy')
})

const pageBg = computed(() => {
  if (stage.value === 'gift')
    return result.value?.gift === 'freeze' ? '#123A63' : '#B8501A'
  if (stage.value === 'chests')
    return chestOpen.value
      ? '#B8501A'
      : ['#100D17', '#241A4A', '#43277F'][Math.min(hits.value, 2)]
  return '#100D17'
})

/** @type {number|undefined} */
let raf
/** @type {number|undefined} */
let countRaf
/** @type {number|undefined} */
let confettiRaf
/** @type {ReturnType<typeof setTimeout>[]} */
const timers = []

/**
 * @param {() => void} fn
 * @param {number} ms
 */
function later(fn, ms) {
  timers.push(setTimeout(fn, reduced ? 0 : ms))
}

/**
 * Spins: the day's wheel first, then with a ticket (bought in place when there is none). The
 * server draws; the wheel then slows down onto a segment of the prize.
 */
async function spin() {
  if (spinning.value || busy.value || stage.value !== 'wheel') return
  const dailyId = daily.value ? props.dailyRewardId : null
  if (!dailyId && tickets.value <= 0) {
    await buyTicket()
    return
  }
  error.value = ''
  busy.value = true
  loseOpen.value = false
  /** @type {import('@/utils/giftWheel').WheelSpin} */
  let drawn
  try {
    drawn = await shop.spinWheel(dailyId)
  } catch (e) {
    busy.value = false
    if (e?.response?.status === 409 && e.response.data?.detail === 'no_ticket') {
      await buyTicket()
      return
    }
    if (dailyId) spun.value = true
    error.value = apiErrorMessage(e, { 409: t('shop.wheel.errors.gone') })
    return
  }
  busy.value = false
  spun.value = true
  result.value = drawn
  shown.value = drawn.balance - drawn.crowns
  turnTo(segmentOf(drawn.prize), () => land(drawn))
}

/**
 * The wheel slowing down onto segment $index (at once with reduced motion).
 *
 * @param {number} index
 * @param {() => void} done
 */
function turnTo(index, done) {
  const jitter = Math.random() * 16 - 8
  const to = landingRotation(rot.value, index, SPIN_SECONDS, jitter)
  if (reduced) {
    rot.value = to
    done()
    return
  }
  const curve = spinCurve(rot.value, to, SPIN_SECONDS, jitter)
  const t0 = performance.now()
  spinning.value = true
  const step = (/** @type {number} */ now) => {
    const ms = now - t0
    rot.value = curve.at(ms)
    if (ms < curve.duration) raf = requestAnimationFrame(step)
    else {
      rot.value = to
      spinning.value = false
      done()
    }
  }
  raf = requestAnimationFrame(step)
}

/** @param {import('@/utils/giftWheel').WheelSpin} drawn */
function land(drawn) {
  if (drawn.crowns > 0) {
    later(() => {
      stage.value = 'gain'
      if (drawn.crowns >= 300) confetti()
    }, 500)
    later(() => countUp(drawn.crowns, drawn.balance), 1400)
  } else if (drawn.prize === 'lose') loseOpen.value = true
  else
    later(() => {
      picked.value = null
      hits.value = 0
      stage.value = 'chests'
    }, 600)
}

/**
 * The reserve counting up to the new balance.
 *
 * @param {number} add
 * @param {number} to
 */
function countUp(add, to) {
  const from = to - add
  floatN.value = add
  floatKey.value += 1
  if (reduced) {
    shown.value = to
    return
  }
  counting.value = true
  const duration = countUpMs(add)
  const t0 = performance.now()
  const step = (/** @type {number} */ now) => {
    const p = Math.min(1, (now - t0) / duration)
    shown.value = add >= 300 ? tweenAt(from, to, p) : Math.floor(from + add * p)
    if (p < 1) countRaf = requestAnimationFrame(step)
    else {
      shown.value = to
      counting.value = false
    }
  }
  countRaf = requestAnimationFrame(step)
}

/**
 * A tap on a chest: the first one picks it (the others go), the third opens it.
 *
 * @param {number} i
 */
function pick(i) {
  if (chestOpen.value || (picked.value !== null && picked.value !== i)) return
  picked.value = i
  hits.value = reduced ? CHEST_TAPS : hits.value + 1
  if (chestOpen.value)
    later(() => {
      stage.value = 'gift'
      confetti()
    }, 1300)
}

/** @param {number} i */
function chestState(i) {
  if (picked.value === null) return 'idle'
  if (picked.value !== i) return 'gone'
  return chestOpen.value ? 'open' : 'hit'
}

/** @param {number} i */
function crackStyle(i) {
  const lit = picked.value === i ? Math.min(hits.value, 2) : 0
  return {
    opacity: lit ? 1 : 0,
    boxShadow: `0 0 ${8 + lit * 10}px ${2 + lit * 4}px rgba(255,230,128,${0.3 + lit * 0.3})`
  }
}

/**
 * @param {{c: string, hi: string}} gem
 * @param {number} i
 */
function gemStyle(gem, i) {
  const lit = picked.value === i ? Math.min(hits.value, 2) : 0
  return {
    background: `radial-gradient(circle at 30% 30%,#fff 0 10%,${gem.hi} 24%,${gem.c} 72%)`,
    boxShadow: `0 0 ${10 + lit * 8}px ${3 + lit * 3}px ${gem.c}99, inset -2px -2px 0 rgba(0,0,0,.25)`
  }
}

/**
 * Sparkles spread over the stage (design).
 *
 * @param {number} n
 */
function sparkles(n) {
  return Array.from({ length: n }, (_, i) => ({
    left: `${8 + ((i * 37) % 84)}%`,
    top: `${6 + ((i * 53) % 86)}%`,
    fontSize: `${10 + ((i * 5) % 12)}px`,
    color: i % 2 ? '#C9B8FF' : '#fff',
    animationDuration: `${1.4 + (i % 4) * 0.3}s`,
    animationDelay: `${(i * 0.19) % 1.4}s`
  }))
}

/** « Relancer »: back to the wheel, spun with a ticket. */
function again() {
  loseOpen.value = false
  stage.value = 'wheel'
  picked.value = null
  hits.value = 0
  nextTick(spin)
}

/** No ticket: the store's sheet, in place; bought, the wheel spins. */
async function buyTicket() {
  const failure = await purchase.open('wheel_ticket')
  if (failure) error.value = failure
}

/**
 * « Pas aujourd'hui », or never again (the profile's setting): the day's wheel lost.
 *
 * @param {boolean} never
 */
async function decline(never) {
  if (!props.dailyRewardId) return
  busy.value = true
  error.value = ''
  try {
    await shop.declineWheel(props.dailyRewardId)
    if (never) await auth.setPreferences({ dailyWheel: false })
    leave()
  } catch (e) {
    error.value = apiErrorMessage(e, { 409: t('shop.wheel.errors.gone') })
  } finally {
    busy.value = false
  }
}

function askNever() {
  $q.dialog({
    title: t('shop.wheel.neverConfirm.title'),
    message: t('shop.wheel.neverConfirm.message'),
    cancel: { label: t('common.actions.cancel'), flat: true },
    ok: { label: t('shop.wheel.neverConfirm.ok'), color: 'primary' },
    persistent: false
  }).onOk(() => decline(true))
}

function leave() {
  if (spinning.value) return
  emit('close')
}

/** Confetti from the bottom corners (design canvas). */
function confetti() {
  if (reduced) return
  confettiKey.value += 1
  nextTick(() => {
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
    const C = ['#C6F432', '#FF8A3D', '#C9B8FF', '#5B3FD0', '#FFFFFF', '#FFD43B']
    /** @type {{x: number, y: number, vx: number, vy: number, w: number, h: number, r: number, vr: number, ph: number, c: string}[]} */
    const P = []
    let f = 0
    const burst = (
      /** @type {number} */ x,
      /** @type {number} */ y,
      /** @type {number} */ n,
      /** @type {number} */ dir
    ) => {
      for (let i = 0; i < n; i++) {
        const a = dir + (Math.random() - 0.5) * 1.1
        const v = 10 + Math.random() * 12
        P.push({
          x,
          y,
          vx: Math.cos(a) * v,
          vy: Math.sin(a) * v,
          w: 6 + Math.random() * 8,
          h: 4 + Math.random() * 6,
          r: Math.random() * 6,
          vr: (Math.random() - 0.5) * 0.4,
          ph: Math.random() * 6,
          c: C[i % C.length]
        })
      }
    }
    burst(0, H, 90, -Math.PI / 3)
    burst(W, H, 90, (-Math.PI * 2) / 3)
    if (confettiRaf !== undefined) cancelAnimationFrame(confettiRaf)
    const tick = () => {
      f++
      ctx.clearRect(0, 0, W, H)
      if (f === 18) {
        burst(W * 0.2, H, 50, -Math.PI / 2.6)
        burst(W * 0.8, H, 50, -Math.PI / 1.6)
      }
      let alive = 0
      for (const p of P) {
        p.vy += 0.32
        p.vx *= 0.985
        p.vy *= 0.985
        p.x += p.vx + Math.sin(f / 10 + p.ph) * 0.6
        p.y += p.vy
        p.r += p.vr
        if (p.y < H + 20) alive++
        ctx.save()
        ctx.translate(p.x, p.y)
        ctx.rotate(p.r)
        ctx.fillStyle = p.c
        ctx.fillRect(
          -p.w / 2,
          -p.h / 2,
          p.w,
          p.h * Math.abs(Math.cos(f / 6 + p.ph)) + 1
        )
        ctx.restore()
      }
      if (alive && f < 600) confettiRaf = requestAnimationFrame(tick)
      else ctx.clearRect(0, 0, W, H)
    }
    confettiRaf = requestAnimationFrame(tick)
  })
}

onMounted(async () => {
  const state = await shop.load().catch(() => null)
  if (state && !result.value) shown.value = state.balance
})

onBeforeUnmount(() => {
  for (const id of [raf, countRaf, confettiRaf])
    if (id !== undefined) cancelAnimationFrame(id)
  timers.forEach(clearTimeout)
})
</script>

<style scoped lang="scss">
$night: #16121f;
$lime: #c6f432;
$gold: linear-gradient(180deg, #fff1a8, #ffd43b 45%, #e7a900);
$outline: 0 0 0 3px #0b0910;

.wheel-screen {
  position: relative;
  min-height: 100%;
  width: 100%;
  overflow: hidden auto;
  color: #fff;
  font-family: 'DM Sans', system-ui, sans-serif;
  transition: background 0.6s ease;
}

.wheel-screen__top {
  position: absolute;
  top: 16px;
  left: 16px;
  right: 16px;
  z-index: 5;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}

.wheel-screen__close {
  width: 44px;
  height: 44px;
  border: none;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
  font-size: 18px;
  cursor: pointer;
}

.wheel-screen__wallet-wrap {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}

.wheel-screen__wallet {
  height: 44px;
  padding: 0 16px 0 10px;
  border-radius: 999px;
  background: $night;
  box-shadow:
    inset 0 0 0 1.5px #3a3450,
    0 8px 24px rgba(0, 0, 0, 0.4);
  display: flex;
  align-items: center;
  gap: 8px;
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: 18px;
  font-variant-numeric: tabular-nums;
  transition:
    transform 0.12s,
    box-shadow 0.3s;

  &--bump {
    transform: scale(1.1);
    box-shadow:
      inset 0 0 0 1.5px #c9b8ff,
      0 8px 24px rgba(0, 0, 0, 0.4);
  }
}

.wheel-screen__float {
  margin: 6px 10px 0 0;
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: 20px;
  color: #c9b8ff;
  animation: wheel-float 1.4s ease-out both;
  pointer-events: none;
}

.wheel-screen__col {
  box-sizing: border-box;
  min-height: 100%;
  max-width: 620px;
  margin: 0 auto;
  padding: 84px 16px 40px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 28px;
}

.wheel-screen__head {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  text-align: center;
  max-width: 520px;
}

.wheel-screen__title {
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: 34px;
  line-height: 1.1;
}

.wheel-screen__sub {
  font-size: 15px;
  line-height: 1.5;
  color: #b4afc6;
  text-wrap: pretty;
  transition: color 0.6s;

  &--warm {
    color: #ffe9da;
  }
}

.wheel-stage {
  position: relative;
  width: min(440px, 84vw);
  aspect-ratio: 1;

  @media (min-width: $breakpoint-md-min) {
    width: min(520px, 56vh);
  }
}

// The wheel
.wheel {
  position: absolute;
  inset: 0;
  transition:
    transform 0.8s cubic-bezier(0.6, -0.35, 0.6, 1),
    opacity 0.7s;

  &--away {
    transform: translateX(-140%) rotate(-140deg) scale(0.6);
    opacity: 0;
    pointer-events: none;
  }
}

.wheel__rim {
  position: absolute;
  inset: -14px;
  border-radius: 50%;
  background: $night;
  box-shadow:
    inset 0 0 0 2px #2e2840,
    0 24px 60px rgba(0, 0, 0, 0.45);
}

.wheel__disc {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  overflow: hidden;
  box-shadow: inset 0 0 0 3px #3a3450;
}

.wheel__slot {
  position: absolute;
  inset: 0;
}

.wheel__label {
  position: absolute;
  top: 7%;
  left: 50%;
  transform: translateX(-50%);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.wheel__amount {
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: 22px;
  line-height: 1;

  &--jackpot {
    font-size: 24px;
    color: $night;
  }
}

.wheel__word {
  font-weight: 800;
  font-size: 11px;
  letter-spacing: 0.08em;
  color: #8a85a0;

  &--gift {
    color: #2a1404;
  }
}

.wheel__pointer {
  position: absolute;
  top: -22px;
  left: 50%;
  margin-left: -16px;
  width: 0;
  height: 0;
  border-left: 16px solid transparent;
  border-right: 16px solid transparent;
  border-top: 30px solid #fff;
  filter: drop-shadow(0 4px 6px rgba(0, 0, 0, 0.5));
  z-index: 2;
}

.wheel__hub {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 26%;
  aspect-ratio: 1;
  border: none;
  border-radius: 50%;
  background: $lime;
  color: $night;
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: clamp(15px, 4vw, 20px);
  cursor: pointer;
  box-shadow:
    0 0 0 8px $night,
    0 0 0 10px #3a3450,
    0 10px 24px rgba(0, 0, 0, 0.5);
  z-index: 2;

  &:disabled {
    cursor: default;
  }
}

// The chests
.wheel-chests {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5%;
}

.wheel-chest {
  position: relative;
  width: 30%;
  aspect-ratio: 1;
  border: none;
  background: none;
  padding: 0;
  cursor: pointer;
  -webkit-tap-highlight-color: transparent;
  animation: wheel-chest-in 0.6s cubic-bezier(0.34, 1.5, 0.64, 1) both;
  transition: transform 0.3s;

  &--big {
    transform: scale(1.18);
  }

  &--gone {
    animation: wheel-gone 0.45s ease-in both;
    pointer-events: none;
  }

  &--open {
    cursor: default;
  }

  &--idle:hover .wheel-chest__body {
    animation: wheel-wig 0.22s linear infinite;
  }
}

.wheel-chest__body {
  position: absolute;
  inset: 0;
  transform-origin: 50% 100%;

  &--hit {
    animation: wheel-hit 0.5s cubic-bezier(0.34, 1.5, 0.64, 1) both;
  }
}

.wheel-chest__shadow {
  position: absolute;
  left: 12%;
  right: 12%;
  bottom: 1%;
  height: 8%;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.5);
  filter: blur(3px);
}

.wheel-chest__glow {
  position: absolute;
  left: -45%;
  right: -45%;
  top: -60%;
  height: 150%;
  border-radius: 50%;
  background: radial-gradient(
    circle,
    rgba(255, 240, 180, 0.95),
    rgba(255, 200, 90, 0.35) 35%,
    transparent 62%
  );
  animation: wheel-glow 0.5s ease-out both;
}

.wheel-chest__box {
  position: absolute;
  left: 6%;
  right: 6%;
  top: 46%;
  bottom: 6%;
  border-radius: 5px 5px 12px 12px;
  background: linear-gradient(180deg, #a8642c, #7a4116 60%, #5e3010);
  box-shadow:
    $outline,
    inset 0 -6px 0 rgba(0, 0, 0, 0.25);
  overflow: hidden;
}

.wheel-chest__band {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 10%;
  background: $gold;

  &--left {
    left: 16%;
  }

  &--right {
    right: 16%;
  }
}

.wheel-chest__trim {
  position: absolute;
  left: 0;
  right: 0;
  top: 0;
  height: 12%;
  background: $gold;
}

.wheel-chest__crack {
  position: absolute;
  left: 8%;
  right: 8%;
  top: 47%;
  height: 3%;
  border-radius: 4px;
  background: #fff6c2;
  transition:
    opacity 0.3s,
    box-shadow 0.3s;
}

.wheel-chest__gold {
  position: absolute;
  left: 9%;
  right: 9%;
  top: 40%;
  height: 12%;
  border-radius: 50%;
  background: radial-gradient(ellipse, #fff6c2, #ffd43b 60%, #c78f00);
  box-shadow: 0 0 30px 14px rgba(255, 236, 150, 0.85);
  animation: wheel-glow 0.3s ease-out both;
}

.wheel-chest__lid {
  position: absolute;
  left: 2%;
  right: 2%;
  top: 16%;
  height: 34%;
  border-radius: 50% 50% 6px 6px / 75% 75% 6px 6px;
  background: linear-gradient(180deg, #c07a3a, #a8642c 45%, #7a4116);
  box-shadow:
    $outline,
    inset 0 5px 0 rgba(255, 255, 255, 0.14);
  overflow: hidden;
  transform-origin: 50% 100%;

  &--off {
    animation: wheel-lid 0.45s cubic-bezier(0.5, 0, 0.7, 1) 0.15s both;
  }
}

.wheel-chest__lid-trim {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 16%;
  background: $gold;
}

.wheel-chest__lock {
  position: absolute;
  left: 36%;
  width: 28%;
  top: 34%;
  height: 28%;
  border-radius: 8px 8px 14px 14px;
  background: $gold;
  box-shadow:
    $outline,
    inset 0 -3px 0 #c78f00;
  display: flex;
  align-items: center;
  justify-content: center;
  transition:
    opacity 0.35s,
    transform 0.45s cubic-bezier(0.5, 0, 0.7, 1);

  &--off {
    opacity: 0;
    transform: translateY(60%) rotate(40deg);
  }
}

.wheel-chest__gem {
  width: 50%;
  aspect-ratio: 1;
  border-radius: 3px;
  transform: rotate(45deg);
  transition: box-shadow 0.3s;
}

// The gift and the gain
.wheel-gift,
.wheel-gain {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
}

.wheel-screen__rays {
  position: absolute;
  inset: 0;
  background: repeating-conic-gradient(
    rgba(201, 184, 255, 0.16) 0 10deg,
    transparent 10deg 24deg
  );
  animation: wheel-ray 18s linear infinite;
  mask-image: radial-gradient(circle, #000 15%, transparent 62%);
  pointer-events: none;

  &--big {
    inset: -25%;
    background: repeating-conic-gradient(
      rgba(201, 184, 255, 0.3) 0 10deg,
      transparent 10deg 24deg
    );
    animation-duration: 8s;
  }

  &--fast {
    animation-duration: 2s;
  }

  &--warm {
    inset: -20%;
    background: repeating-conic-gradient(
      rgba(255, 214, 120, 0.28) 0 10deg,
      transparent 10deg 24deg
    );
    animation-duration: 16s;
  }
}

.wheel-screen__sparkle {
  position: absolute;
  animation: wheel-twinkle 1.6s ease-in-out infinite;
  pointer-events: none;
}

.wheel-gift__glow,
.wheel-gain__glow {
  position: absolute;
  width: 70%;
  aspect-ratio: 1;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(255, 138, 61, 0.45), transparent 65%);
  animation: wheel-glow 0.6s ease-out both;
}

.wheel-gift__glow--ice {
  background: radial-gradient(circle, rgba(79, 178, 255, 0.55), transparent 65%);
}

.wheel-gain__glow {
  width: 65%;
  background: radial-gradient(circle, rgba(91, 63, 208, 0.45), transparent 65%);
  animation-duration: 0.5s;

  &--big {
    width: 90%;
    background: radial-gradient(circle, rgba(91, 63, 208, 0.7), transparent 65%);
  }
}

.wheel-gift__albert {
  position: relative;
  height: 82%;
  object-fit: contain;
  animation: wheel-pop 0.7s cubic-bezier(0.34, 1.6, 0.64, 1) 0.1s both;
  filter: drop-shadow(0 18px 30px rgba(0, 0, 0, 0.5));
}

.wheel-gift__ice {
  position: relative;
  width: 46%;
  aspect-ratio: 1;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: radial-gradient(circle at 32% 28%, #e2f2ff, #9ed8ff 40%, #4fb2ff 75%);
  box-shadow:
    0 0 0 6px rgba(255, 255, 255, 0.25),
    0 24px 50px rgba(0, 0, 0, 0.4);
  color: #0f3a66;
  font-size: clamp(64px, 18vw, 120px);
  animation: wheel-pop 0.7s cubic-bezier(0.34, 1.6, 0.64, 1) 0.1s both;
}

.wheel-gain__pill {
  position: relative;
  height: 36px;
  padding: 0 14px;
  border-radius: 999px;
  background: $night;
  color: #c9b8ff;
  box-shadow: inset 0 0 0 1.5px #5b3fd0;
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: 20px;
  display: flex;
  align-items: center;
  animation: wheel-pop 0.5s cubic-bezier(0.34, 1.6, 0.64, 1) both;

  &--big {
    background: $lime;
    color: $night;
    box-shadow: none;
  }
}

.wheel-gain__stack {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  animation: wheel-pop 0.6s cubic-bezier(0.34, 1.6, 0.64, 1) 0.1s both;

  &--buzz {
    animation: wheel-buzz 0.16s linear infinite;
  }

  &--buzz-fast {
    animation-duration: 0.08s;
  }
}

.wheel-gain__count {
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: 56px;
  line-height: 1;
  font-variant-numeric: tabular-nums;

  &--tick {
    animation: wheel-tick 0.12s ease-out both;
  }
}

.wheel-gain__label {
  position: relative;
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.08em;
  color: #8a85a0;
}

// Under the stage
.wheel-screen__bottom {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;
  text-align: center;
  min-height: 50px;
  width: 100%;
  max-width: 380px;
}

.wheel-screen__hint {
  font-size: 13.5px;
  font-weight: 700;
  color: #8a85a0;
}

.wheel-screen__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
  animation: wheel-in 0.4s both;
}

.wheel-screen__action {
  min-height: 50px;
  padding: 0 28px;
  border: none;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.08);
  color: #fff;
  font-weight: 800;
  font-size: 15px;
  cursor: pointer;

  &--main {
    background: $lime;
    color: $night;
  }

  &:disabled {
    opacity: 0.6;
    cursor: default;
  }
}

.wheel-screen__link {
  border: none;
  background: none;
  color: #c9b8ff;
  font-size: 13.5px;
  font-weight: 700;
  text-decoration: underline;
  cursor: pointer;
  padding: 6px;

  &:hover {
    color: #fff;
  }
}

.wheel-screen__gift-text {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  animation: wheel-in 0.5s 0.5s both;
}

.wheel-screen__badge {
  height: 26px;
  padding: 0 11px;
  border-radius: 999px;
  background: $night;
  color: #ffb07a;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  display: flex;
  align-items: center;

  &--ice {
    color: #9ed8ff;
  }
}

.wheel-screen__gift-title {
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: 28px;
  line-height: 1.1;
}

.wheel-screen__gift-body {
  font-size: 15px;
  line-height: 1.5;
  color: #ffe9da;
  text-wrap: pretty;
}

.wheel-screen__error {
  background: rgba(255, 61, 90, 0.16);
  color: #fff;
}

// The skull's sheet
.wheel-lose {
  position: absolute;
  inset: 0;
  z-index: 10;
  background: rgba(8, 6, 12, 0.72);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
}

.wheel-lose__card {
  width: 100%;
  max-width: 360px;
  box-sizing: border-box;
  padding: 28px 24px 24px;
  border-radius: 24px;
  background: $night;
  box-shadow:
    inset 0 0 0 1.5px #3a3450,
    0 30px 80px rgba(0, 0, 0, 0.6);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 14px;
  text-align: center;
  animation: wheel-in 0.35s ease-out;
}

.wheel-lose__kicker {
  font-weight: 800;
  font-size: 12px;
  letter-spacing: 0.1em;
  color: #8a85a0;
}

.wheel-lose__title {
  font-family: 'Bricolage Grotesque', 'DM Sans', sans-serif;
  font-weight: 800;
  font-size: 32px;
  line-height: 1.1;
}

.wheel-lose__body {
  font-size: 15px;
  line-height: 1.5;
  color: #b4afc6;
  text-wrap: pretty;
}

.wheel-lose__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
  margin-top: 6px;
}

.wheel-screen__confetti {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  pointer-events: none;
  z-index: 40;
}

@keyframes wheel-in {
  0% {
    opacity: 0;
    transform: translateY(10px) scale(0.96);
  }
  100% {
    opacity: 1;
    transform: none;
  }
}

@keyframes wheel-tick {
  0% {
    transform: translateY(-40%);
    opacity: 0.3;
  }
  100% {
    transform: none;
    opacity: 1;
  }
}

@keyframes wheel-buzz {
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

@keyframes wheel-wig {
  0%,
  100% {
    transform: rotate(0);
  }
  25% {
    transform: rotate(-5deg);
  }
  75% {
    transform: rotate(5deg);
  }
}

@keyframes wheel-hit {
  0% {
    transform: scale(1);
  }
  20% {
    transform: scale(0.86, 1.12) translateY(-10%) rotate(-6deg);
  }
  45% {
    transform: scale(1.1, 0.92) rotate(5deg);
  }
  70% {
    transform: rotate(-3deg);
  }
  100% {
    transform: none;
  }
}

@keyframes wheel-chest-in {
  0% {
    opacity: 0;
    transform: translateY(60px) scale(0.5);
  }
  70% {
    opacity: 1;
    transform: translateY(-8px) scale(1.06);
  }
  100% {
    opacity: 1;
    transform: none;
  }
}

@keyframes wheel-lid {
  0% {
    transform: none;
    opacity: 1;
  }
  100% {
    transform: translateY(-90%) rotate(-32deg);
    opacity: 0;
  }
}

@keyframes wheel-gone {
  0% {
    opacity: 1;
    transform: none;
  }
  100% {
    opacity: 0;
    transform: scale(0.6) translateY(30px);
  }
}

@keyframes wheel-glow {
  0% {
    opacity: 0;
    transform: scale(0.3);
  }
  100% {
    opacity: 1;
    transform: scale(1);
  }
}

@keyframes wheel-ray {
  to {
    transform: rotate(360deg);
  }
}

@keyframes wheel-pop {
  0% {
    transform: scale(0) rotate(-10deg);
  }
  60% {
    transform: scale(1.1) rotate(3deg);
  }
  100% {
    transform: none;
  }
}

@keyframes wheel-float {
  0% {
    opacity: 0;
    transform: translateY(10px);
  }
  20% {
    opacity: 1;
  }
  100% {
    opacity: 0;
    transform: translateY(-40px);
  }
}

@keyframes wheel-twinkle {
  0%,
  100% {
    opacity: 0;
    transform: scale(0.3);
  }
  50% {
    opacity: 1;
    transform: scale(1);
  }
}

@media (prefers-reduced-motion: reduce) {
  .wheel,
  .wheel-chest,
  .wheel-chest__body,
  .wheel-chest__lid,
  .wheel-gain__stack,
  .wheel-gift__albert,
  .wheel-gift__ice,
  .wheel-screen__rays,
  .wheel-screen__sparkle {
    animation: none !important;
    transition: none !important;
  }
}
</style>
