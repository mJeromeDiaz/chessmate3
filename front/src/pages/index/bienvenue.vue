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
          <div class="auth-kicker">COMPTE PRÊT · DERNIÈRE ÉTAPE</div>
          <h1 class="auth-title">Lie ton compte Lichess</h1>
        </div>
        <div class="welcome__talk">
          <img v-if="aaron" :src="aaron" alt="" class="welcome__aaron" />
          <div class="welcome__bubble">
            <div class="welcome__who">AARON</div>
            Avec tes parties Lichess, on prépare tes analyses, ton répertoire et
            des puzzles à ton niveau.
          </div>
        </div>
        <ul class="welcome__perks">
          <li v-for="perk in LICHESS_PERKS" :key="perk">
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
          {{ linking ? 'Direction Lichess…' : 'Lier mon compte Lichess' }}
        </button>
        <button
          type="button"
          class="auth-btn auth-btn--ghost"
          data-testid="welcome-skip"
          @click="step = 'done'"
          >Plus tard</button
        >
      </div>

      <!-- Ready. -->
      <div v-else key="done" class="auth-step" data-testid="welcome-done">
        <div class="welcome__card">
          <div class="welcome__card-shade" />
          <div class="welcome__card-kicker">{{
            linked ? 'LICHESS LIÉ ✓' : 'COMPTE PRÊT'
          }}</div>
          <div class="welcome__card-title">C’est parti !</div>
          <div class="welcome__card-text">
            {{
              linked
                ? 'Ton compte Lichess est lié. Tes profs t’attendent.'
                : 'Tu pourras lier Lichess plus tard depuis ton profil.'
            }}
          </div>
          <img v-if="albert" :src="albert" alt="" class="welcome__albert" />
        </div>
        <router-link
          to="/session/new"
          class="auth-btn"
          data-testid="welcome-first-session"
          >Créer ma première session →</router-link
        >
        <router-link to="/" class="auth-btn auth-btn--ghost"
          >Aller au tableau de bord</router-link
        >
      </div>
    </Transition>
  </AuthShell>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import AuthShell from '@/components/auth/AuthShell.vue'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import { markLinkFromWelcome } from '@/utils/auth/authFlow'
import { LICHESS_PERKS } from '@/utils/landing/content'
import { profImage } from '@/utils/prof/images'

/**
 * The first screen of a new account (design "Connexion", end of the flow): link Lichess, or later;
 * then "C'est parti !". Opened after a sign-up with Google or Lichess, and after the first sign-in
 * that follows the email verification. A link started here comes back here (OAuth callback).
 */
definePage({ meta: { auth: 'required' } })

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
