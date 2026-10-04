<template>
  <a
    :href="authApi.oauthLoginUrl('lichess')"
    class="landing-btn landing-btn--dark"
    data-testid="landing-lichess"
    ><span class="landing-btn__knight" aria-hidden="true">♞︎</span>Continuer avec
    Lichess</a
  >
</template>

<script setup>
import { authApi } from '@/services/api'

/** Sign-in (or sign-up) with Lichess, the landing page's main call to action. */
</script>
