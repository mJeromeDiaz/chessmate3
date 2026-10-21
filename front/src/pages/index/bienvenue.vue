<template>
  <AuthShell :legal="false">
    <Transition name="auth-slide" mode="out-in">
      <div v-if="!ready" key="wait" class="auth-step">
        <q-spinner size="32px" color="primary" />
      </div>

      <!-- Link Lichess (optional). -->
      <div
        v-else-if="step === 'link'"
        key="link"
        class="auth-step"
        data-testid="welcome-link"
      >
        <div>
          <div class="auth-kicker">{{ $t('auth.welcome.kicker') }}</div>
          <h1 class="auth-title">{{ $t('auth.welcome.title') }}</h1>
        </div>
        <div class="welcome__talk">
          <img v-if="aaron" :src="aaron" alt="" class="welcome__aaron" />
          <div class="welcome__bubble">
            <div class="welcome__who">{{ $t('auth.welcome.who') }}</div>
            {{ $t('auth.welcome.bubble') }}
          </div>
        </div>
        <ul class="welcome__perks">
          <li v-for="perk in lichessPerks()" :key="perk">
            <span class="welcome__tick" aria-hidden="true">✓</span>{{ perk }}
          </li>
        </ul>
        <p v-if="error" class="auth-error">{{ error }}</p>
        <button
          type="button"
          class="auth-btn welcome__lichess"
          :disabled="linking"
          data-testid="welcome-link-lichess"
          @click="link"
        >
          <span class="welcome__knight" aria-hidden="true">♞︎</span>
          {{ linking ? $t('auth.welcome.linking') : $t('auth.welcome.link') }}
        </button>
        <button
          type="button"
          class="auth-btn auth-btn--ghost"
          data-testid="welcome-skip"
          @click="step = 'done'"
          >{{ $t('auth.welcome.later') }}</button
        >
      </div>

      <!-- Ready. -->
      <div v-else key="done" class="auth-step" data-testid="welcome-done">
        <div class="welcome__card">
          <div class="welcome__card-shade" />
          <div class="welcome__card-kicker">{{
            linked
              ? $t('auth.welcome.linkedKicker')
              : $t('auth.welcome.readyKicker')
          }}</div>
          <div class="welcome__card-title">{{ $t('auth.welcome.go') }}</div>
          <div class="welcome__card-text">
            {{
              linked
                ? $t('auth.welcome.linkedText')
                : $t('auth.welcome.laterText')
            }}
          </div>
          <img v-if="albert" :src="albert" alt="" class="welcome__albert" />
        </div>
        <router-link
          :to="TOUR_PATH"
          class="auth-btn"
          data-testid="welcome-tour"
          @click="restartTour"
          >{{ $t('auth.welcome.tour') }}</router-link
        >
        <router-link to="/" class="auth-btn auth-btn--ghost">{{
          $t('auth.welcome.dashboard')
        }}</router-link>
      </div>
    </Transition>
  </AuthShell>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import AuthShell from '@/components/auth/AuthShell.vue'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import { TOUR_PATH, markLinkFromWelcome } from '@/utils/auth/authFlow'
import { TOUR_STEP_KEY } from '@/utils/onboarding'
import { lichessPerks } from '@/utils/landing/content'
import { profImage } from '@/utils/prof/images'

/**
 * The first screen of a new account (design "Connexion", end of the flow): link Lichess, or later;
 * then "C'est parti !", which leads to the guided tour (docs/ONBOARDING.md). Opened after a
 * sign-up with Google or Lichess, and after the first sign-in that follows the email verification. A link started here comes back here (OAuth callback).
 */
definePage({
  meta: {
    auth: 'required',
    header: { title: 'layout.header.titles.welcome', parent: false }
  }
})

const auth = useAuthStore()
const aaron = profImage('aaron-wink')
const albert = profImage('albert-joy')

const ready = ref(false)
/** @type {import('vue').Ref<'link'|'done'>} */
const step = ref('link')
const linking = ref(false)
const error = ref('')

const linked = computed(
  () =>
    !!auth.profile?.identities?.some(
      identity => identity.provider === 'lichess'
    )
)

onMounted(async () => {
  if (!auth.profile) await auth.fetchProfile().catch(() => {})
  step.value = linked.value ? 'done' : 'link'
  ready.value = true
})

/** A new account's tour starts from its first screen (another account may have left a step). */
function restartTour() {
  try {
    localStorage.setItem(TOUR_STEP_KEY, '0')
  } catch {
    // Storage unavailable: the tour starts from the first screen anyway.
  }
}

async function link() {
  linking.value = true
  error.value = ''
  try {
    markLinkFromWelcome()
    window.location.assign(await auth.startLink('lichess'))
  } catch (e) {
    error.value = apiErrorMessage(e)
    linking.value = false
  }
}
</script>

<style scoped lang="scss">
.welcome__talk {
  display: flex;
  align-items: flex-end;
  gap: 10px;
}

.welcome__aaron {
  flex: none;
  width: 74px;
}

.welcome__bubble {
  flex: 1;
  margin-bottom: 8px;
  padding: 10px 14px;
  border: 2px solid var(--cm-line);
  border-radius: 18px 18px 18px 4px;
  background: var(--cm-surface);
  font-size: 14px;
  line-height: 1.45;
  text-wrap: pretty;
}

.welcome__who {
  font-size: 11px;
  font-weight: 700;
  color: #0f6bba;
}

.welcome__perks {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 14px;

  li {
    display: flex;
    align-items: center;
    gap: 10px;
  }
}

.welcome__tick {
  flex: none;
  width: 24px;
  height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: #c6f432;
  color: #1b1530;
  font-weight: 800;
  font-size: 13px;
}

.welcome__lichess {
  height: 56px;
  background: #1b1530;
  color: #fff;
}

.welcome__knight {
  font-size: 22px;
  line-height: 1;
}

.welcome__card {
  position: relative;
  overflow: hidden;
  min-height: 120px;
  padding: 20px 120px 20px 20px;
  border-radius: 24px;
  background: #c6f432;
  color: #1b1530;
}

.welcome__card-shade {
  position: absolute;
  inset: 0 0 0 auto;
  width: 55%;
  background: #8dba0a;
  opacity: 0.55;
  mask-image: linear-gradient(to right, transparent, #000);
}

.welcome__card-kicker,
.welcome__card-title,
.welcome__card-text {
  position: relative;
}

.welcome__card-kicker {
  font-size: 12px;
  font-weight: 700;
}

.welcome__card-title {
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 26px;
  line-height: 1.1;
}

.welcome__card-text {
  margin-top: 4px;
  font-size: 14px;
  line-height: 1.4;
  text-wrap: pretty;
}

.welcome__albert {
  position: absolute;
  right: 4px;
  bottom: -6px;
  width: 112px;
}
</style>
