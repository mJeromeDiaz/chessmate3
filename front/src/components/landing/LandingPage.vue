<template>
  <q-page class="landing" data-testid="landing">
    <LandingHeader />
    <LandingHero />
    <LandingModules />
    <LandingSteps />
    <LandingProfs />
    <LandingWoodpecker />
    <LandingLichess />
    <LandingCta />
  </q-page>
</template>

<script setup>
import LandingCta from '@/components/landing/LandingCta.vue'
import LandingHeader from '@/components/landing/LandingHeader.vue'
import LandingHero from '@/components/landing/LandingHero.vue'
import LandingLichess from '@/components/landing/LandingLichess.vue'
import LandingModules from '@/components/landing/LandingModules.vue'
import LandingProfs from '@/components/landing/LandingProfs.vue'
import LandingSteps from '@/components/landing/LandingSteps.vue'
import LandingWoodpecker from '@/components/landing/LandingWoodpecker.vue'

/**
 * The visitors' home page (design "Landing"). The layout hides its own header here: the page has
 * its own, with in-page anchors. Showcase content: utils/landing/content.js.
 */
</script>

<!-- Shared by the landing sections (not scoped): layout, headings, buttons, overlays. -->
<style lang="scss">
.landing {
  background: var(--cm-page);
  color: var(--cm-ink);
}

.landing-section {
  padding: clamp(64px, 9vw, 112px) 24px 0;
  // Keep the sticky header from covering a section reached from an anchor.
  scroll-margin-top: 72px;

  @media (max-width: $breakpoint-xs-max) {
    padding-left: 16px;
    padding-right: 16px;
    scroll-margin-top: 110px;
  }
}

.landing-section__inner {
  max-width: 1200px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 32px;
}

.landing-kicker {
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-brand);
}

.landing-h2 {
  margin: 6px 0 0;
  font-size: clamp(32px, 4.2vw, 48px);
  line-height: 1.05;
  text-wrap: balance;
}

.landing-pill {
  align-self: flex-start;
  background: #c6f432;
  color: #1b1530;
  font-weight: 700;
  font-size: 13px;
  padding: 6px 12px;
  border-radius: 999px;
}

// The darker half of a coloured card, fading in from the left.
.landing-shade {
  position: absolute;
  top: 0;
  right: 0;
  bottom: 0;
  mask-image: linear-gradient(to right, transparent, #000);
  pointer-events: none;
}

.landing-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.landing-btn {
  height: 56px;
  padding: 0 22px;
  border-radius: 18px;
  font-weight: 700;
  font-size: 16px;
  display: flex;
  align-items: center;
  gap: 10px;
  text-decoration: none;

  &--dark {
    background: #1b1530;
    color: #fff;

    &:hover {
      color: #fff;
    }
  }

  &--light {
    background: #fff;
    color: #1b1530;

    &:hover {
      color: #1b1530;
    }
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.landing-btn__knight {
  font-size: 22px;
  line-height: 1;
}

// A professor's speech bubble.
.landing-bubble {
  background: var(--cm-page);
  color: var(--cm-ink);
  border: 2px solid var(--cm-line);
  border-radius: 16px 16px 16px 4px;
  padding: 10px 12px;
}

.landing-bubble__label {
  font-size: 11px;
  font-weight: 700;
}
</style>
