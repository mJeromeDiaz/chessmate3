<template>
  <header class="landing-header" data-testid="landing-header">
    <div class="landing-header__inner">
      <router-link to="/" class="cm-brand-name landing-header__brand"
        >Don't Stay <span>Rooky</span></router-link
      >
      <nav class="landing-header__nav">
        <button
          v-for="anchor in LANDING_ANCHORS"
          :key="anchor.id"
          type="button"
          class="landing-header__anchor"
          @click="scrollToSection(anchor.id)"
          >{{ anchor.label }}</button
        >
      </nav>
      <div class="landing-header__actions">
        <LocaleMenu />
        <ThemeMenu />
        <router-link
          to="/login"
          class="landing-header__btn landing-header__btn--soft"
          data-testid="landing-login"
          >{{ $t('landing.signIn') }}</router-link
        >
        <router-link
          to="/register"
          class="landing-header__btn landing-header__btn--dark"
          data-testid="landing-start"
          >{{ $t('landing.start') }}</router-link
        >
      </div>
    </div>
  </header>
</template>

<script setup>
import LocaleMenu from '@/components/theme/LocaleMenu.vue'
import ThemeMenu from '@/components/theme/ThemeMenu.vue'
import { LANDING_ANCHORS, scrollToSection } from '@/utils/landing/content'

/** The landing page's own sticky header: in-page anchors, language, theme, sign-in and sign-up. */
</script>

<style scoped lang="scss">
.landing-header {
  position: sticky;
  top: 0;
  z-index: 10;
  background: color-mix(in srgb, var(--cm-page) 88%, transparent);
  backdrop-filter: blur(12px);
  border-bottom: 1px solid var(--cm-line);
}

.landing-header__inner {
  max-width: 1200px;
  margin: 0 auto;
  padding: 14px 32px;
  display: flex;
  align-items: center;
  gap: 12px 40px;
  flex-wrap: wrap;

  @media (max-width: $breakpoint-xs-max) {
    padding: 12px 16px;
  }
}

// The wordmark gets room to breathe: no squeezing by the anchors, a touch of tracking.
.landing-header__brand {
  flex: none;
  font-size: 24px;
  line-height: 1;
  letter-spacing: 0.015em;
  word-spacing: 0.08em;
}

.landing-header__nav {
  display: flex;
  align-items: center;
  gap: 8px 28px;
  flex: 1;
  flex-wrap: wrap;

  @media (max-width: $breakpoint-xs-max) {
    order: 3;
    flex-basis: 100%;
    gap: 16px;
  }
}

.landing-header__anchor {
  padding: 0;
  border: 0;
  background: none;
  font: inherit;
  font-weight: 600;
  font-size: 14.5px;
  color: var(--cm-ink-soft);
  cursor: pointer;

  &:hover {
    color: var(--cm-brand);
  }
}

.landing-header__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.landing-header__btn {
  height: 42px;
  padding: 0 16px;
  border-radius: 14px;
  font-weight: 700;
  font-size: 14px;
  display: flex;
  align-items: center;
  text-decoration: none;

  &--soft {
    background: var(--cm-brand-soft);
    color: var(--cm-brand-deep);
  }

  &--dark {
    background: #1b1530;
    color: #fff;

    &:hover {
      color: #fff;
    }

    // The page's ink is light in dark mode: the button keeps a visible edge.
    body.body--dark & {
      box-shadow: inset 0 0 0 1px var(--cm-line);
    }
  }
}
</style>
