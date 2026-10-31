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
        <button
          type="button"
          class="landing-header__burger"
          :aria-label="$t('landing.menu.open')"
          :aria-expanded="open"
          aria-controls="landing-menu"
          data-testid="landing-menu-open"
          @click="open = true"
        >
          <span /><span /><span />
        </button>
      </div>
    </div>

    <!-- Outside the header: its backdrop filter would hold a fixed element inside it. -->
    <Teleport to="body">
      <div
        v-if="open"
        id="landing-menu"
        class="landing-menu"
        role="dialog"
        aria-modal="true"
        :aria-label="$t('landing.menu.open')"
        data-testid="landing-menu"
        @keydown.esc="open = false"
      >
        <div class="landing-menu__top">
          <span class="cm-brand-name landing-menu__brand"
            >Don't Stay <span>Rooky</span></span
          >
          <LocaleMenu />
          <ThemeMenu />
          <button
            ref="closeButton"
            type="button"
            class="landing-menu__close"
            :aria-label="$t('landing.menu.close')"
            data-testid="landing-menu-close"
            @click="open = false"
            >×</button
          >
        </div>
        <nav class="landing-menu__nav">
          <button
            v-for="(anchor, i) in LANDING_ANCHORS"
            :key="anchor.id"
            type="button"
            class="landing-menu__anchor cm-heading"
            :data-testid="`landing-menu-${anchor.id}`"
            @click="go(anchor.id)"
            ><span
              class="landing-menu__num"
              :style="{ color: MENU_COLORS[i % MENU_COLORS.length] }"
              >{{ String(i + 1).padStart(2, '0') }}</span
            >{{ anchor.label }}</button
          >
        </nav>
        <div class="landing-menu__actions">
          <a
            :href="authApi.oauthLoginUrl('lichess')"
            class="landing-menu__btn landing-menu__btn--lime"
            ><span aria-hidden="true">♞︎</span>{{ $t('landing.lichess') }}</a
          >
          <router-link to="/login" class="landing-menu__btn">{{
            $t('landing.menu.account')
          }}</router-link>
        </div>
      </div>
    </Teleport>
  </header>
</template>

<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue'
import LocaleMenu from '@/components/theme/LocaleMenu.vue'
import ThemeMenu from '@/components/theme/ThemeMenu.vue'
import { authApi } from '@/services/api'
import { LANDING_ANCHORS, scrollToSection } from '@/utils/landing/content'

/**
 * The landing page's own sticky header: in-page anchors, language, theme, sign-in and sign-up. On
 * a phone (design "Landing mobile"): the brand, "Commencer" and a burger opening a full-screen
 * menu with the numbered anchors, the language and theme, Lichess and the sign-in.
 */

/** The numbers' colours in the menu, one per section. */
const MENU_COLORS = ['#FF6FAE', '#4FC3F7', '#C6F432', '#FFD43B']

const open = ref(false)
/** @type {import('vue').Ref<HTMLButtonElement|null>} */
const closeButton = ref(null)

// The page under the menu stays still; the focus goes into the menu.
watch(open, value => {
  document.body.style.overflow = value ? 'hidden' : ''
  if (value) nextTick(() => closeButton.value?.focus())
})

onBeforeUnmount(() => {
  document.body.style.overflow = ''
})

/** @param {string} id section element id */
function go(id) {
  open.value = false
  // Once the page can scroll again.
  nextTick(() => scrollToSection(id))
}
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

  // Room for "Commencer" and the burger on a phone.
  @media (max-width: $breakpoint-xs-max) {
    flex: 1;
    min-width: 0;
    font-size: 20px;
  }
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

// A phone: the anchors, language, theme and sign-in move into the menu.
@media (max-width: $breakpoint-xs-max) {
  .landing-header__nav,
  .landing-header__actions
    > :not(.landing-header__btn--dark, .landing-header__burger) {
    display: none;
  }
}

.landing-header__burger {
  display: none;
  width: 44px;
  height: 44px;
  border: 0;
  border-radius: 12px;
  background: var(--cm-brand-soft);
  cursor: pointer;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 5px;

  span {
    width: 18px;
    height: 2px;
    border-radius: 2px;
    background: var(--cm-brand-deep);
  }

  @media (max-width: $breakpoint-xs-max) {
    display: flex;
  }
}

.landing-menu {
  position: fixed;
  inset: 0;
  z-index: 3000;
  background: #16121f;
  color: #fff;
  display: flex;
  flex-direction: column;
  padding: 12px 16px 28px;
  overflow-y: auto;
}

.landing-menu__top {
  display: flex;
  align-items: center;
  gap: 4px;
}

.landing-menu__brand {
  flex: 1;
  font-size: 20px;
  color: #fff;

  // The wordmark's accent, lime on the dark menu.
  span {
    color: #c6f432;
  }
}

.landing-menu__close {
  width: 44px;
  height: 44px;
  margin-left: 4px;
  border: 0;
  border-radius: 12px;
  background: #2b2540;
  color: #fff;
  font-size: 24px;
  line-height: 1;
  cursor: pointer;
}

.landing-menu__nav {
  flex: 1;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 4px;
  padding: 24px 0;
}

.landing-menu__anchor {
  display: flex;
  align-items: baseline;
  gap: 14px;
  padding: 10px 0;
  border: 0;
  background: none;
  color: #fff;
  font-size: 36px;
  line-height: 1;
  text-align: left;
  cursor: pointer;
}

.landing-menu__num {
  font-family: inherit;
  font-size: 13px;
  font-weight: 700;
}

.landing-menu__actions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.landing-menu__btn {
  height: 54px;
  border-radius: 18px;
  background: #2b2540;
  color: #fff;
  font-weight: 700;
  font-size: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  text-decoration: none;

  &:hover {
    color: #fff;
  }

  &--lime {
    background: #c6f432;
    color: #1b1530;

    &:hover {
      color: #1b1530;
    }

    span {
      font-size: 22px;
      line-height: 1;
    }
  }
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
