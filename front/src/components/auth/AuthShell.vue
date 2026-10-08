<template>
  <q-page class="auth-shell">
    <AuthHero :wide="wide" class="auth-shell__hero" />
    <div class="auth-shell__panel">
      <div class="auth-shell__inner">
        <slot />
        <p v-if="legal" class="auth-shell__legal">
          En continuant, tu acceptes les
          <router-link to="/conditions">conditions d’utilisation</router-link>
          et la
          <router-link to="/confidentialite"
            >politique de confidentialité</router-link
          >.
        </p>
      </div>
    </div>
  </q-page>
</template>

<script setup>
/**
 * The frame of the sign-in screens (design "Connexion"): the professors' hero above the form on a
 * phone (the form slides up as a sheet), on its left on a wide screen. The form's look (fields,
 * buttons, step titles) is shared through the `auth-*` classes below.
 */
import { computed } from 'vue'
import { useQuasar } from 'quasar'
import AuthHero from '@/components/auth/AuthHero.vue'

defineProps({
  /** The terms line under the form (sign-in and sign-up screens). */
  legal: { type: Boolean, default: true }
})

const $q = useQuasar()
/** Same breakpoint as the header's (1024 px). */
const wide = computed(() => $q.screen.gt.sm)
</script>

<style scoped lang="scss">
.auth-shell {
  display: flex;
  flex-direction: column;
  background: var(--cm-page);
}

.auth-shell__panel {
  position: relative;
  margin-top: -22px;
  padding: 26px 20px 32px;
  border-radius: 28px 28px 0 0;
  background: var(--cm-page);
  animation: auth-sheet-in 0.7s cubic-bezier(0.34, 1.3, 0.64, 1) 1.1s both;
}

.auth-shell__inner {
  width: 100%;
  max-width: 420px;
  margin: 0 auto;
}

.auth-shell__legal {
  margin: 22px 0 0;
  font-size: 12px;
  line-height: 1.5;
  color: var(--cm-muted);
  text-align: center;
  text-wrap: pretty;
}

@media (min-width: 1024px) {
  .auth-shell {
    flex-direction: row;
    min-height: 100vh;
  }

  .auth-shell__hero {
    flex: 1;
    min-width: 0;
  }

  .auth-shell__panel {
    flex: none;
    width: 500px;
    margin: 0;
    padding: 40px 56px;
    border-radius: 0;
    display: flex;
    flex-direction: column;
    justify-content: center;
    animation-name: auth-panel-in;
  }
}

@keyframes auth-sheet-in {
  from {
    transform: translateY(640px);
  }
}

@keyframes auth-panel-in {
  from {
    opacity: 0;
    transform: translateX(120px);
  }
}

@media (prefers-reduced-motion: reduce) {
  .auth-shell__panel {
    animation: none;
  }
}
</style>

<style lang="scss">
// The form's look on every sign-in screen (design "Connexion"), outside `scoped` so that the pages'
// own markup gets it. Colours come from the theme tokens (dark mode included).
.auth-step {
  display: flex;
  flex-direction: column;
  gap: 18px;
}

.auth-title {
  margin: 0;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 28px;
  line-height: 1.1;
  color: var(--cm-ink);
}

.auth-lead {
  margin: 6px 0 0;
  font-size: 14.5px;
  line-height: 1.45;
  color: var(--cm-ink-soft);
  text-wrap: pretty;

  b {
    color: var(--cm-ink);
  }
}

.auth-kicker {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.02em;
  color: var(--cm-lime-ink);
}

.auth-field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.auth-label {
  font-weight: 700;
  font-size: 13px;
  color: var(--cm-ink);
}

.auth-label-row {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
}

.auth-input {
  width: 100%;
  box-sizing: border-box;
  height: 52px;
  padding: 0 16px;
  border: 1.5px solid var(--cm-line);
  border-radius: 16px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  font: inherit;
  font-size: 16px;
  outline: none;
  transition: border-color 0.2s;

  &:focus {
    border-color: var(--cm-brand);
  }

  &--error {
    border-color: #ff6fae;
  }

  &--ok {
    border-color: #a6d61e;
  }
}

.auth-input-wrap {
  position: relative;

  .auth-input {
    padding-right: 84px;
  }
}

.auth-input-toggle {
  position: absolute;
  right: 8px;
  top: 8px;
  height: 36px;
  padding: 0 10px;
  border: 0;
  border-radius: 10px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font: inherit;
  font-weight: 700;
  font-size: 12px;
  cursor: pointer;
}

.auth-error {
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  color: var(--cm-danger);
}

.auth-note {
  padding: 14px 16px;
  border-radius: 18px;
  background: var(--cm-lime-soft);
  color: var(--cm-lime-ink);
  font-size: 14.5px;
  line-height: 1.45;

  &--info {
    background: var(--cm-info-soft);
    color: var(--cm-info);
  }

  &--danger {
    background: var(--cm-danger-soft);
    color: var(--cm-danger);
  }
}

.auth-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  width: 100%;
  height: 54px;
  border: 0;
  border-radius: 18px;
  background: var(--cm-ink);
  color: var(--cm-page);
  font: inherit;
  font-weight: 700;
  font-size: 15px;
  text-decoration: none;
  cursor: pointer;
  transition:
    background 0.25s,
    opacity 0.2s;

  &:disabled {
    background: var(--cm-faint);
    cursor: default;
  }

  &--brand {
    background: #8b6bff;
    color: #fff;
  }

  &--outline {
    border: 1.5px solid var(--cm-line);
    background: var(--cm-surface);
    color: var(--cm-ink);
  }

  &--ghost {
    height: 44px;
    background: transparent;
    color: var(--cm-muted);
  }
}

.auth-back {
  align-self: flex-start;
  display: flex;
  align-items: center;
  gap: 6px;
  height: 40px;
  padding: 0 14px 0 10px;
  border: 0;
  border-radius: 12px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font: inherit;
  font-weight: 700;
  font-size: 14px;
  cursor: pointer;
}

.auth-link {
  padding: 0;
  border: 0;
  background: none;
  color: var(--cm-brand);
  font: inherit;
  font-weight: 700;
  font-size: 13.5px;
  text-decoration: none;
  cursor: pointer;

  &:disabled {
    color: var(--cm-faint);
    cursor: default;
  }
}

.auth-chip {
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--cm-brand-soft);
  color: var(--cm-brand-deep);
  font-weight: 700;
}

.auth-sep {
  display: flex;
  align-items: center;
  gap: 12px;
  color: var(--cm-muted);
  font-size: 13px;

  &::before,
  &::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--cm-line);
  }
}

.auth-foot {
  margin: 0;
  font-size: 14px;
  color: var(--cm-ink-soft);
  text-align: center;
}

// Step changes: the new step slides in from the side it comes from.
.auth-slide-enter-active,
.auth-slide-leave-active {
  transition:
    opacity 0.28s ease,
    transform 0.32s cubic-bezier(0.2, 0.8, 0.2, 1);
}

.auth-slide-enter-from {
  opacity: 0;
  transform: translateX(18px);
}

.auth-slide-leave-to {
  opacity: 0;
  transform: translateX(-18px);
}

// The start step's entrance, element after element (`--d`: its delay).
.auth-rise {
  animation: auth-rise 0.55s cubic-bezier(0.34, 1.6, 0.64, 1) both;
  animation-delay: var(--d, 0ms);
}

@keyframes auth-rise {
  from {
    opacity: 0;
    transform: translateY(16px);
  }
}

@media (prefers-reduced-motion: reduce) {
  .auth-rise,
  .auth-slide-enter-active,
  .auth-slide-leave-active {
    animation: none;
    transition: none;
  }
}
</style>
