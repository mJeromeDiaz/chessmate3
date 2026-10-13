<template>
  <a
    :href="authApi.oauthLoginUrl('lichess')"
    class="auto-repertoire-btn"
    @click="markAfterSignIn(path)"
    ><span class="auto-repertoire-btn__knight" aria-hidden="true">♞︎</span
    ><slot>Générer mon répertoire</slot></a
  >
</template>

<script setup>
import { authApi } from '@/services/api'
import {
  AUTO_REPERTOIRE_PATH,
  markAfterSignIn
} from '@/utils/auth/authFlow'

/**
 * The landing page's "from my games" call to action: a Lichess sign-in (or sign-up, through the
 * early access screen) that leads to `path` (the repertoire from my games by default, or the
 * tailored session), the account being linked to Lichess by then. A real link: the destination is
 * noted on click, before the browser leaves.
 */
defineProps({
  /** Where the sign-in leads (one of the pages authFlow allows). */
  path: { type: String, default: AUTO_REPERTOIRE_PATH }
})
</script>

<style scoped lang="scss">
.auto-repertoire-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  background: #5ee6b0;
  color: #0b3b29;
  font-weight: 700;
  text-decoration: none;
  white-space: nowrap;
  transition: transform 0.15s ease;

  &:hover {
    color: #0b3b29;
    transform: translateY(-1px);
  }

  &:focus-visible {
    outline: 3px solid var(--cm-brand);
    outline-offset: 2px;
  }
}

.auto-repertoire-btn__knight {
  font-size: 1.3em;
  line-height: 1;
}
</style>
