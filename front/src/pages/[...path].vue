<template>
  <div class="nf" data-testid="not-found">
    <div class="nf__top">
      <router-link to="/" class="nf__logo">Don't Stay <span>Rooky</span></router-link>
      <button
        type="button"
        class="nf__replay"
        data-testid="not-found-replay"
        @click="play"
        >↺ Rejouer le coup</button
      >
    </div>

    <div class="nf__main">
      <div class="nf__scene">
        <div class="nf__board" :style="{ transform: f.boardTf }" aria-hidden="true">
          <div class="nf__square nf__square--from" :style="{ opacity: f.fromOp }" />
          <div class="nf__square nf__square--to" :style="{ opacity: f.toOp }" />
          <div
            class="nf__page-wrap"
            :style="{ transform: f.pageTf, opacity: f.pageOp }"
          >
            <div class="nf__page">
              <div class="nf__page-corner" />
              <div class="nf__page-line" style="width: 80%" />
              <div class="nf__page-line" style="width: 60%" />
              <div class="nf__page-code">404</div>
            </div>
          </div>
          <div
            class="nf__knight"
            :style="{ left: f.kLeft, top: f.kTop, transform: f.kTf }"
            >♞︎</div
          >
          <div class="nf__badge" :style="{ transform: f.badgeTf }">??</div>
        </div>
        <div class="nf__tray">
          <div class="nf__tray-left">
            <span class="nf__tray-label">PIÈCE CAPTURÉE</span>
            <div class="nf__tray-page" :style="{ transform: f.trayTf }"
              >404</div
            >
          </div>
          <span class="nf__move">404. Cxpage ??</span>
        </div>
      </div>

      <div class="nf__text">
        <div
          class="nf__kicker"
          :style="{ opacity: f.kickOp, transform: f.kickTf }"
          >ERREUR 404 · PAGE INTROUVABLE</div
        >
        <h1
          class="nf__title"
          :style="{ transform: f.titleTf }"
          aria-label="Blunder !"
        >
          <span
            v-for="(l, i) in f.letters"
            :key="i"
            class="nf__letter"
            :class="{ 'nf__letter--bang': l.bang }"
            :style="{
              transitionDelay: l.delay,
              transform: l.tf,
              opacity: l.op
            }"
            aria-hidden="true"
            >{{ l.ch }}</span
          >
        </h1>
        <div class="nf__sub" :style="{ opacity: f.subOp, transform: f.subTf }">
          <div class="nf__sub-title">La page était juste là.</div>
          <div class="nf__sub-text">Tu l’as laissée se faire capturer.</div>
        </div>
        <div class="nf__aaron" :style="{ opacity: f.endOp, transform: f.endTf }">
          <div class="nf__aaron-face">
            <img :src="aaronWow" alt="" />
          </div>
          <div class="nf__bubble">
            <div class="nf__bubble-kicker">AARON · OUPS</div>
            <div class="nf__bubble-text"
              >Pas de panique, on reprend la partie depuis le début.</div
            >
          </div>
        </div>
        <div
          class="nf__actions"
          :style="{ opacity: f.endOp, transform: f.endTf }"
        >
          <template v-if="auth.isAuthenticated">
            <router-link
              to="/"
              class="nf__btn nf__btn--primary"
              data-testid="not-found-dashboard"
              >Retour au tableau de bord</router-link
            >
            <router-link
              to="/puzzle"
              class="nf__btn nf__btn--soft"
              data-testid="not-found-puzzle"
              >Se venger sur un puzzle</router-link
            >
          </template>
          <router-link
            v-else
            to="/"
            class="nf__btn nf__btn--primary"
            data-testid="not-found-home"
            >Retour à l’accueil</router-link
          >
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Unknown route (design "Erreur 404"): a knight captures the page, "BLUNDER!", Aaron and the way
 * back (the dashboard, or the landing page for a visitor). With reduced motion, the last frame at
 * once.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import aaronWow from '@/assets/profs/aaron-wow.png'
import { useAuthStore } from '@/stores/auth'
import { LAST_STEP, STEPS_MS, notFoundFrame } from '@/utils/notFound'

const auth = useAuthStore()
const step = ref(0)
const f = computed(() => notFoundFrame(step.value))
/** @type {ReturnType<typeof setTimeout>[]} */
let timers = []

function clear() {
  timers.forEach(clearTimeout)
  timers = []
}

function play() {
  clear()
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
    step.value = LAST_STEP
    return
  }
  step.value = 0
  timers = STEPS_MS.map((ms, i) => setTimeout(() => (step.value = i + 1), ms))
}

onMounted(play)
onBeforeUnmount(clear)
</script>

<style scoped lang="scss">
// The board keeps the design's colours in both themes; the rest reads the app's tokens.
.nf {
  min-height: 100vh;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  padding: 20px clamp(16px, 4vw, 48px) 32px;
  gap: 24px;
  background: var(--cm-page);
  color: var(--cm-ink);
  overflow-x: hidden;
}

.nf__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.nf__logo {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 22px;
  color: var(--cm-ink);
  text-decoration: none;

  span {
    color: var(--cm-brand);
  }
}

.nf__replay {
  height: 40px;
  padding: 0 14px;
  border: none;
  border-radius: 14px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font: inherit;
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;
}

.nf__main {
  flex: 1;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: clamp(28px, 6vw, 80px);
}

.nf__scene {
  width: min(420px, 100%);
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.nf__board {
  position: relative;
  width: 100%;
  aspect-ratio: 5 / 4;
  border-radius: 24px;
  overflow: hidden;
  container-type: inline-size;
  background-color: #e4dcff;
  background-image: repeating-conic-gradient(#cbbdff 0 25%, transparent 0 50%);
  background-size: 40% 50%;
  transition: transform 0.35s cubic-bezier(0.34, 2, 0.64, 1);
}

.nf__square {
  position: absolute;
  width: 20%;
  height: 25%;
  transition: opacity 0.3s;

  &--from {
    left: 0;
    top: 25%;
    background: #ff6fae;
  }

  &--to {
    left: 40%;
    top: 50%;
    background: #e03c86;
  }
}

.nf__page-wrap {
  position: absolute;
  left: 40%;
  top: 50%;
  width: 20%;
  height: 25%;
  display: flex;
  align-items: center;
  justify-content: center;
  transition:
    transform 0.75s cubic-bezier(0.3, 0, 0.4, 1),
    opacity 0.75s;
}

.nf__page {
  position: relative;
  width: 52%;
  height: 72%;
  background: #fff;
  border: 2.5px solid #1b1530;
  border-radius: 3cqw 0.6cqw 3cqw 3cqw;
  box-sizing: border-box;
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  padding: 0 1.6cqw 1.6cqw;
  gap: 0.9cqw;
  overflow: hidden;
  color: #1b1530;
}

.nf__page-corner {
  position: absolute;
  right: -2.5px;
  top: -2.5px;
  width: 3.4cqw;
  height: 3.4cqw;
  background: linear-gradient(225deg, #cbbdff 50%, #dcd8e8 50%);
  border-left: 2.5px solid #1b1530;
  border-bottom: 2.5px solid #1b1530;
  border-radius: 0 0 0 1cqw;
}

.nf__page-line {
  height: 0.9cqw;
  background: #dcd8e8;
  border-radius: 1cqw;
}

.nf__page-code {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 3.6cqw;
  line-height: 1;
}

.nf__knight {
  position: absolute;
  width: 20%;
  height: 25%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
  font-size: 15cqw;
  line-height: 1;
  color: #1b1530;
  z-index: 2;
  transition:
    left 0.42s cubic-bezier(0.5, 0, 0.2, 1),
    top 0.42s cubic-bezier(0.5, 0, 0.2, 1),
    transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.nf__badge {
  position: absolute;
  left: calc(60% - 9cqw);
  top: calc(50% + 1.2cqw);
  z-index: 3;
  height: 7.6cqw;
  min-width: 7.6cqw;
  padding: 0 1.4cqw;
  box-sizing: border-box;
  border-radius: 999px;
  background: #fff;
  border: 2px solid #e03c86;
  color: #c02670;
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 4cqw;
  transition: transform 0.4s cubic-bezier(0.34, 2, 0.64, 1);
}

.nf__tray {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  background: var(--cm-surface);
  border-radius: 18px;
  padding: 10px 12px;
}

.nf__tray-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.nf__tray-label {
  font-size: 11px;
  font-weight: 700;
  color: var(--cm-muted);
}

.nf__tray-page {
  width: 22px;
  height: 28px;
  background: #fff;
  color: #1b1530;
  border: 2px solid #1b1530;
  border-radius: 5px 2px 5px 5px;
  box-sizing: border-box;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 8px;
  padding-bottom: 3px;
  transition: transform 0.45s cubic-bezier(0.34, 2, 0.64, 1);
}

.nf__move {
  background: #ffe6f1;
  color: #c02670;
  font-weight: 800;
  font-size: 13px;
  padding: 5px 10px;
  border-radius: 999px;
  font-variant-numeric: tabular-nums;
}

.nf__text {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-width: 520px;
  min-width: 0;
}

.nf__kicker {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.06em;
  color: #c02670;
  transition:
    opacity 0.35s,
    transform 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
}

.nf__title {
  display: flex;
  margin: 0;
  transform-origin: 0% 100%;
  transition: transform 0.5s cubic-bezier(0.34, 2.2, 0.64, 1);
}

.nf__letter {
  display: inline-block;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: clamp(60px, 11vw, 132px);
  line-height: 0.9;
  letter-spacing: -0.02em;
  color: var(--cm-ink);
  transition:
    transform 0.55s cubic-bezier(0.34, 1.8, 0.64, 1),
    opacity 0.25s;

  &--bang {
    color: #e03c86;
  }
}

.nf__sub {
  display: flex;
  flex-direction: column;
  gap: 4px;
  transition:
    opacity 0.4s,
    transform 0.5s cubic-bezier(0.34, 1.35, 0.64, 1);
}

.nf__sub-title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: clamp(20px, 2.4vw, 26px);
  line-height: 1.15;
}

.nf__sub-text {
  font-size: 17px;
  line-height: 1.45;
  color: var(--cm-ink-soft);
  text-wrap: pretty;
}

.nf__aaron {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  transition:
    opacity 0.4s,
    transform 0.55s cubic-bezier(0.34, 1.35, 0.64, 1);
}

.nf__aaron-face {
  width: 72px;
  height: 72px;
  flex: none;
  border-radius: 20px;
  background: #ff6fae;
  overflow: hidden;
  position: relative;

  img {
    position: absolute;
    left: 0;
    bottom: 0;
    width: 100%;
    height: 100%;
    object-fit: contain;
    object-position: center bottom;
  }
}

.nf__bubble {
  flex: 1;
  min-width: 0;
  background: var(--cm-surface);
  border: 2px solid #ffe6f1;
  border-radius: 20px 20px 20px 6px;
  padding: 10px 14px;
}

.nf__bubble-kicker {
  font-size: 11px;
  font-weight: 700;
  color: #c02670;
}

.nf__bubble-text {
  font-size: 15px;
  line-height: 1.4;
}

.nf__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  transition:
    opacity 0.4s 0.1s,
    transform 0.55s 0.1s cubic-bezier(0.34, 1.35, 0.64, 1);
}

.nf__btn {
  height: 54px;
  padding: 0 22px;
  border-radius: 16px;
  display: flex;
  align-items: center;
  text-decoration: none;

  &--primary {
    background: var(--cm-ink);
    color: var(--cm-page);
    font-weight: 800;
    font-size: 14px;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    box-shadow: 0 4px 0 var(--cm-ink-soft);
  }

  &--soft {
    background: var(--cm-brand-soft);
    color: var(--cm-brand-deep);
    font-weight: 700;
    font-size: 15px;
  }
}

@media (prefers-reduced-motion: reduce) {
  .nf *,
  .nf__board {
    transition: none !important;
  }
}
</style>
