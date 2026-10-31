<template>
  <AuthShell>
    <div class="auth-step" data-testid="guest-account">
      <div>
        <div class="auth-kicker">{{ $t('guest.account.kicker') }}</div>
        <h1 class="auth-title">{{ $t('guest.account.title') }}</h1>
      </div>
      <p class="auth-lead">{{ $t('guest.account.lead') }}</p>
      <ul class="guest-account__perks">
        <li v-for="i in PERKS" :key="i">
          <span class="guest-account__tick" aria-hidden="true">✓</span
          >{{ $t(`guest.account.perks.${i}`) }}
        </li>
      </ul>
      <a
        :href="authApi.oauthLoginUrl('lichess')"
        class="auth-btn auth-btn--brand"
        data-testid="guest-account-lichess"
        ><span class="guest-account__knight" aria-hidden="true">♞︎</span
        >{{ $t('guest.account.lichess') }}</a
      >
      <router-link
        to="/register"
        class="auth-btn auth-btn--outline"
        data-testid="guest-account-email"
        >{{ $t('guest.account.email') }}</router-link
      >
      <p class="auth-note auth-note--info">{{ $t('guest.account.key') }}</p>
    </div>
  </AuthShell>
</template>

<script setup>
import { useRouter } from 'vue-router'
import AuthShell from '@/components/auth/AuthShell.vue'
import { authApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'

/**
 * What an account brings, for a guest (docs/GUEST.md): where every locked page and card leads.
 * Lichess first (a new account needs an invitation key: the callback asks for it, the early
 * access screen then); or the email sign-up. Either way the guest becomes the account, keeping
 * what it played. An account has nothing to do here: home.
 */
definePage({
  meta: {
    auth: 'required',
    guestOpen: true,
    header: { title: 'guest.account.header' }
  }
})

/** The perks listed (`guest.account.perks.<i>`). */
const PERKS = [0, 1, 2, 3]

const auth = useAuthStore()
if (!auth.isGuest) useRouter().replace('/')
</script>

<style scoped lang="scss">
.guest-account__perks {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 10px;

  li {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 15px;
    line-height: 1.4;
  }
}

.guest-account__tick {
  width: 26px;
  height: 26px;
  flex: none;
  border-radius: 8px;
  background: var(--cm-lime);
  color: #1b1530;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 13px;
}

.guest-account__knight {
  margin-right: 10px;
  font-size: 22px;
  line-height: 1;
}
</style>
