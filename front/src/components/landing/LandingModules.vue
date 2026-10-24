<template>
  <section id="modules" class="landing-section">
    <div class="landing-section__inner landing-modules">
      <div class="landing-modules__head">
        <div class="landing-modules__heading">
          <div class="landing-kicker">{{ $t('landing.modules.kicker') }}</div>
          <h2 class="landing-h2 cm-heading">{{
            $t('landing.modules.title', { n: liveCount })
          }}</h2>
        </div>
        <p class="landing-modules__lead">
          {{ $t('landing.modules.lead') }}
        </p>
      </div>

      <div class="landing-modules__families">
        <span
          v-for="module in LANDING_MODULES"
          :key="module.id"
          class="landing-modules__family cm-heading"
          :class="{ 'landing-modules__family--soon': !module.available }"
          :style="{ background: module.bg, color: module.ink }"
          :data-testid="`landing-module-${module.id}`"
          ><span class="landing-modules__glyph" aria-hidden="true">{{
            module.glyph
          }}</span
          >{{ module.title
          }}<span v-if="!module.available" class="landing-modules__soon">{{
            $t('landing.modules.soon')
          }}</span></span
        >
      </div>

      <div class="landing-modules__marquee" data-testid="landing-variants">
        <div
          v-for="(row, r) in rows"
          :key="r"
          class="landing-modules__row"
          :class="{ 'landing-modules__row--reverse': r % 2 }"
          :style="{ animationDuration: `${50 + r * 12}s` }"
        >
          <div
            v-for="copy in 2"
            :key="copy"
            class="landing-modules__run"
            :aria-hidden="copy === 2 ? 'true' : undefined"
          >
            <span
              v-for="(v, i) in row"
              :key="i"
              class="landing-modules__variant"
              ><span
                class="landing-modules__swatch"
                :style="{ background: v.module.bg }"
              /><b>{{ v.module.title }}</b
              ><span class="landing-modules__meta">{{ v.meta }}</span></span
            >
          </div>
        </div>
        <div class="landing-modules__fade landing-modules__fade--left" />
        <div class="landing-modules__fade landing-modules__fade--right" />
      </div>
    </div>
  </section>
</template>

<script setup>
import { LANDING_MODULES, variantRows } from '@/utils/landing/content'

/**
 * Every module of the app (those not built yet tagged "Bientôt"), then rows of real settings
 * scrolling on their own (each row twice, for a seamless loop; still with reduced motion).
 */

const liveCount = LANDING_MODULES.filter(m => m.available).length
const rows = variantRows(3)
</script>

<style scoped lang="scss">
.landing-modules {
  gap: 28px;
}

.landing-modules__head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
}

.landing-modules__heading {
  max-width: 640px;
}

.landing-modules__lead {
  margin: 0;
  max-width: 380px;
  font-size: 16px;
  line-height: 1.5;
  color: var(--cm-ink-soft);
  text-wrap: pretty;
}

.landing-modules__families {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.landing-modules__family {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 16px;
  padding: 9px 14px;
  border-radius: 14px;

  &--soon {
    opacity: 0.6;
  }
}

.landing-modules__glyph {
  font-size: 17px;
  line-height: 1;
}

.landing-modules__soon {
  font-family: var(--cm-body, inherit);
  font-size: 11px;
  font-weight: 800;
  background: rgba(255, 255, 255, 0.7);
  color: #1b1530;
  padding: 2px 7px;
  border-radius: 999px;
}

.landing-modules__marquee {
  position: relative;
  background: #1b1530;
  border-radius: 32px;
  padding: 20px 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: 10px;

  // Edge to edge on a phone: out of the section's 16 px gutters, square corners.
  @media (max-width: $breakpoint-xs-max) {
    margin: 0 -16px;
    border-radius: 0;
  }
}

.landing-modules__row {
  display: flex;
  width: max-content;
  animation: landing-marquee-left linear infinite;

  &--reverse {
    animation-name: landing-marquee-right;
  }

  @media (prefers-reduced-motion: reduce) {
    animation: none;
  }
}

.landing-modules__run {
  display: flex;
  gap: 8px;
  padding-right: 8px;
}

.landing-modules__variant {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
  background: #2a2347;
  color: #fff;
  font-size: 13.5px;
  padding: 8px 12px;
  border-radius: 12px;
  white-space: nowrap;

  b {
    font-weight: 700;
  }
}

.landing-modules__swatch {
  width: 9px;
  height: 9px;
  border-radius: 3px;
  flex: none;
}

.landing-modules__meta {
  color: #b4afc6;
}

.landing-modules__fade {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 80px;
  pointer-events: none;

  &--left {
    left: 0;
    background: linear-gradient(to right, #1b1530, rgba(27, 21, 48, 0));
  }

  &--right {
    right: 0;
    background: linear-gradient(to left, #1b1530, rgba(27, 21, 48, 0));
  }
}

@keyframes landing-marquee-left {
  from {
    transform: translateX(0);
  }

  to {
    transform: translateX(-50%);
  }
}

@keyframes landing-marquee-right {
  from {
    transform: translateX(-50%);
  }

  to {
    transform: translateX(0);
  }
}
</style>
