<template>
  <q-page class="early" :class="`early--${stage}`">
    <div class="early__flood">
      <div class="early__flood-shade" />
      <div class="early__flood-glow" />
      <FloatingPieces :count="12" />
    </div>
    <ConfettiBurst
      v-if="confetti"
      :key="confetti"
      class="early__confetti"
      pieces
    />

    <div class="early__column">
      <div class="early__top">
        <router-link to="/login" class="early__back">← Connexion</router-link>
        <div class="early__pill"><span class="early__dot" />ACCÈS ANTICIPÉ</div>
        <span class="early__top-spacer" />
      </div>

      <div class="early__stage">
        <div class="early__rings" :class="{ 'early__rings--fast': checking }">
          <div class="early__ring-disc" />
          <div class="early__ring-dash" />
          <div
            v-for="i in 3"
            :key="i"
            class="early__ring"
            :style="{ animationDelay: `${(i - 1) * 0.8}s` }"
          />
        </div>
        <img :key="profSrc" :src="profSrc" alt="" class="early__prof" />
      </div>

      <h1
        :key="stage"
        class="early__title"
        :aria-label="`${title[0]} ${title[1]}`"
      >
        <span class="early__line" aria-hidden="true">
          <span
            v-for="(l, i) in lettersOf(title[0], 0)"
            :key="i"
            class="early__ch"
            :style="{ animationDelay: l.delay, '--tilt': l.tilt }"
            >{{ l.ch }}</span
          >
        </span>
        <span class="early__line early__line--light" aria-hidden="true">
          <span
            v-for="(l, i) in lettersOf(title[1], title[0].length)"
            :key="i"
            class="early__ch"
            :style="{ animationDelay: l.delay, '--tilt': l.tilt }"
            >{{ l.ch }}</span
          >
        </span>
      </h1>

      <div class="early__sheet">
        <Transition name="auth-slide" mode="out-in">
          <!-- The key, or a request to join. -->
          <div v-if="stage === 'form'" key="form" class="auth-step">
            <div class="early__tabs" role="tablist">
              <div
                class="early__thumb"
                :class="{ 'early__thumb--right': tab === 'ask' }"
              />
              <button
                type="button"
                role="tab"
                class="early__tab"
                :class="{ 'early__tab--on': tab === 'key' }"
                :aria-selected="tab === 'key'"
                data-testid="access-tab-key"
                @click="tab = 'key'"
                >J’ai une clé</button
              >
              <button
                type="button"
                role="tab"
                class="early__tab"
                :class="{ 'early__tab--on': tab === 'ask' }"
                :aria-selected="tab === 'ask'"
                data-testid="access-tab-ask"
                @click="tab = 'ask'"
                >Demander l’accès</button
              >
            </div>

            <form
              v-if="tab === 'key'"
              class="auth-step"
              novalidate
              @submit.prevent="activate"
            >
              <div
                v-if="fromProvider"
                class="auth-note auth-note--info"
                data-testid="access-provider-note"
              >
                Ton compte {{ fromProvider.label }} n’est lié à aucun compte
                Don't Stay Rooky. Saisis ta clé : on te renverra chez
                {{ fromProvider.label }} pour le créer.
              </div>
              <p v-else class="auth-lead early__lead">
                Don't Stay Rooky est en accès anticipé. Colle la clé reçue par
                e-mail (ou ouvre son lien).
              </p>
              <div
                class="auth-field"
                :class="{ early__shake: shake }"
                @animationend="shake = 0"
              >
                <label class="auth-label" for="invitation-key"
                  >Clé d’invitation</label
                >
                <input
                  id="invitation-key"
                  v-model="key"
                  class="auth-input early__key"
                  :class="{
                    'auth-input--error': keyError,
                    'auth-input--ok': keyComplete && !keyError
                  }"
                  autocomplete="off"
                  autocapitalize="off"
                  spellcheck="false"
                  placeholder="32 lettres et chiffres"
                  data-testid="invitation-key"
                  @input="keyError = ''"
                />
                <p
                  class="early__key-msg"
                  :class="{
                    'early__key-msg--error': keyError,
                    'early__key-msg--ok': keyComplete && !keyError
                  }"
                >
                  {{ keyMessage }}
                </p>
              </div>
              <button
                type="submit"
                class="auth-btn early__cta"
                :disabled="checking || !keyComplete"
                data-testid="invitation-activate"
                >{{ checking ? 'Vérification…' : 'Activer mon compte' }}</button
              >
            </form>

            <form v-else class="auth-step" novalidate @submit.prevent="ask">
              <p class="auth-lead early__lead">
                Les places s’ouvrent par vagues. Laisse ton e-mail, on te
                prévient.
              </p>
              <div class="auth-field">
                <label class="auth-label" for="access-email"
                  >Adresse e-mail</label
                >
                <input
                  id="access-email"
                  v-model="askEmail"
                  type="email"
                  class="auth-input"
                  :class="{ 'auth-input--error': askError }"
                  autocomplete="email"
                  placeholder="toi@exemple.fr"
                  data-testid="access-email"
                  @input="askError = ''"
                />
                <p v-if="askError" class="auth-error">{{ askError }}</p>
              </div>
              <!-- Honeypot: hidden from people, filled by bots (the API then drops the request). -->
              <input
                v-model="honeypot"
                type="text"
                name="website"
                class="early__honeypot"
                tabindex="-1"
                autocomplete="off"
                aria-hidden="true"
              />
              <button
                type="submit"
                class="auth-btn early__cta"
                :disabled="asking"
                data-testid="access-submit"
                >{{ asking ? 'Envoi…' : 'Rejoindre la liste' }}</button
              >
            </form>
          </div>

          <!-- A valid key: create the account. -->
          <div v-else-if="stage === 'ok'" key="ok" class="auth-step">
            <div class="early__stamp-row">
              <div class="early__stamp">✓</div>
              <div>
                <div class="auth-kicker">CLÉ VALABLE</div>
                <div class="auth-lead early__lead">{{ keyValidity }}</div>
              </div>
            </div>

            <ProviderButtons
              :invitation-key="checkedKey"
              :lead="fromProvider?.id ?? 'lichess'"
              verb="Créer mon compte avec"
              @leave="markWelcome"
            />

            <div class="auth-sep">ou par e-mail</div>

            <form class="auth-step" novalidate @submit.prevent="signUp">
              <div class="auth-field">
                <label class="auth-label" for="register-email"
                  >Adresse e-mail</label
                >
                <input
                  id="register-email"
                  v-model="email"
                  type="email"
                  class="auth-input"
                  autocomplete="email"
                  placeholder="toi@exemple.fr"
                  data-testid="register-email"
                />
              </div>
              <NewPasswordFields
                v-model="password"
                v-model:confirmation="confirmation"
              />
              <p v-if="error" class="auth-error" data-testid="register-error">{{
                error
              }}</p>
              <button
                type="submit"
                class="auth-btn"
                :disabled="loading || !signUpReady"
                data-testid="register-submit"
                >{{ loading ? 'Création…' : 'Créer mon compte' }}</button
              >
            </form>
          </div>

          <!-- Account created: the verification link is on its way. -->
          <div
            v-else-if="stage === 'sent'"
            key="sent"
            class="auth-step early__center"
          >
            <div class="early__stamp early__stamp--big">✉</div>
            <p class="auth-lead" data-testid="register-done">
              Si l’adresse <b>{{ email }}</b> peut être utilisée, un e-mail de
              vérification vient de lui être envoyé. Ouvre son lien pour activer
              ton compte.
            </p>
            <router-link to="/login" class="auth-btn"
              >Aller à la connexion</router-link
            >
          </div>

          <!-- On the waiting list. -->
          <div v-else key="queue" class="auth-step early__center">
            <div class="early__ticket" data-testid="access-queued">
              <div class="early__ticket-kicker">LISTE D’ATTENTE</div>
              <div class="early__ticket-big">♞︎</div>
              <div class="early__ticket-stamp">C’EST NOTÉ ✓</div>
            </div>
            <p class="auth-lead">
              On t’écrira sur <b>{{ askEmail }}</b> dès qu’une place se libère.
            </p>
            <button
              type="button"
              class="auth-btn auth-btn--outline"
              @click="backToKey"
            >
              J’ai déjà une clé
            </button>
          </div>
        </Transition>
      </div>
    </div>
  </q-page>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NewPasswordFields from '@/components/auth/NewPasswordFields.vue'
import ProviderButtons from '@/components/auth/ProviderButtons.vue'
import ConfettiBurst from '@/components/training/ConfettiBurst.vue'
import FloatingPieces from '@/components/training/result/FloatingPieces.vue'
import { authApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import {
  INVITATION_REASONS,
  KEY_ERRORS,
  KEY_LENGTH,
  PASSWORD_MIN_LENGTH,
  dropLetters,
  isEmail,
  keyFormatError,
  markWelcome,
  normalizeKey,
  providerById
} from '@/utils/auth/authFlow'
import { formatDate } from '@/utils/format'
import { profImage } from '@/utils/prof/images'
import { browserTimezone } from '@/utils/timezone'

/**
 * Early access (design "Accès anticipé", docs/EARLY_ACCESS.md): an invitation key opens an account
 * (Lichess, Google, or an address and a password); without one, the visitor joins the waiting list.
 * The invitation email links here with `?key=`; a Lichess/Google sign-in refused for want of a key
 * comes back here with `?provider=&reason=`, and the key then sends the visitor to that provider
 * again (the server keeps nothing between the two trips).
 */
definePage({ meta: { auth: 'guest', landing: true } })

/** @typedef {'form'|'ok'|'sent'|'queue'} Stage */

const TITLES = {
  form: ['Tu es', 'en avance !'],
  ok: ['Bienvenue', 'à bord !'],
  sent: ['Plus qu’un', 'clic !'],
  queue: ['Tu es sur', 'la liste !']
}

const route = useRoute()
const router = useRouter()

/** @type {import('vue').Ref<Stage>} */
const stage = ref('form')
/** @type {import('vue').Ref<'key'|'ask'>} */
const tab = ref('key')
const confetti = ref(0)

const key = ref('')
const keyError = ref('')
const checking = ref(false)
const shake = ref(0)
/** The key the server accepted, and its expiry (null: never). */
const checkedKey = ref('')
const keyExpiresAt = ref(/** @type {string|null} */ (null))

const email = ref('')
const password = ref('')
const confirmation = ref('')
const loading = ref(false)
const error = ref('')

const askEmail = ref('')
const askError = ref('')
const asking = ref(false)
const honeypot = ref('')

/** The page's first title (it drops in after the intro); later ones drop at once. */
const firstTitle = ref(true)
watch(stage, () => (firstTitle.value = false))

/** The provider a refused sign-in came from. */
const fromProvider = ref(/** @type {ReturnType<typeof providerById>} */ (null))

const title = computed(() => TITLES[stage.value])
const profSrc = computed(() => {
  if (stage.value === 'ok' || stage.value === 'sent')
    return profImage('aaron-laugh')
  if (checking.value)
    return profImage('albert-v2-think') || profImage('aaron-wink')
  return profImage('aaron-wink')
})

const cleanKey = computed(() => normalizeKey(key.value))
const keyComplete = computed(() => keyFormatError(cleanKey.value) === '')
const keyMessage = computed(() => {
  if (keyError.value) return keyError.value
  if (checking.value) return 'Vérification de ta clé…'
  if (keyComplete.value) return 'Clé complète ✓'
  return `${cleanKey.value.length} / ${KEY_LENGTH}`
})
const keyValidity = computed(() =>
  keyExpiresAt.value === null
    ? 'Elle n’expire pas.'
    : `Valable jusqu’au ${formatDate(keyExpiresAt.value)}.`
)
const signUpReady = computed(
  () =>
    isEmail(email.value) &&
    password.value.length >= PASSWORD_MIN_LENGTH &&
    password.value === confirmation.value
)

/**
 * @param {string} text
 * @param {number} offset
 */
const lettersOf = (text, offset) =>
  dropLetters(text, offset).map(l => ({
    ...l,
    // The first title waits for the flood and the professor.
    delay: firstTitle.value ? `calc(1s + ${l.delay})` : l.delay
  }))

/**
 * @param {unknown} e an axios error
 * @returns {string}
 */
function keyErrorMessage(e) {
  const code = /** @type {any} */ (e)?.response?.data?.error
  return KEY_ERRORS[code] ?? apiErrorMessage(e)
}

/** @param {string} message */
function refuseKey(message) {
  keyError.value = message
  shake.value++
}

/** Asks the API whether the key can open an account; a valid one opens the sign-up stage. */
async function activate() {
  const formatError = keyFormatError(cleanKey.value)
  if (formatError) {
    refuseKey(formatError)
    return
  }
  checking.value = true
  keyError.value = ''
  try {
    const { expiresAt } = await authApi.checkInvitation(cleanKey.value)
    checkedKey.value = cleanKey.value
    keyExpiresAt.value = expiresAt
    stage.value = 'ok'
    confetti.value++
  } catch (e) {
    refuseKey(keyErrorMessage(e))
  } finally {
    checking.value = false
  }
}

async function signUp() {
  loading.value = true
  error.value = ''
  try {
    email.value = email.value.trim()
    await authApi.register(
      email.value,
      password.value,
      browserTimezone(),
      checkedKey.value
    )
    stage.value = 'sent'
  } catch (e) {
    const code = /** @type {any} */ (e)?.response?.data?.error
    if (KEY_ERRORS[code]) {
      // Spent or revoked meanwhile: back to the key.
      stage.value = 'form'
      tab.value = 'key'
      refuseKey(KEY_ERRORS[code])
    } else {
      error.value = apiErrorMessage(e)
    }
  } finally {
    loading.value = false
    password.value = ''
    confirmation.value = ''
  }
}

async function ask() {
  if (!isEmail(askEmail.value)) {
    askError.value = 'Saisis une adresse e-mail valide.'
    return
  }
  asking.value = true
  askError.value = ''
  try {
    askEmail.value = askEmail.value.trim()
    await authApi.requestAccess(askEmail.value, honeypot.value)
    stage.value = 'queue'
  } catch (e) {
    askError.value = apiErrorMessage(e, {
      422: 'Saisis une adresse e-mail valide.',
      429: 'Trop de demandes depuis cette connexion. Réessaie plus tard.'
    })
  } finally {
    asking.value = false
  }
}

function backToKey() {
  stage.value = 'form'
  tab.value = 'key'
}

// The invitation email links to #/register?key=…, a refused provider sign-in to
// ?provider=&reason=: take them, then drop them from the URL. Watched, not read once: a second link
// opened in the same tab only changes the hash (same page instance).
watch(
  () => route.query,
  query => {
    const { key: linkKey, provider, reason } = query
    if (
      typeof linkKey !== 'string' &&
      provider === undefined &&
      reason === undefined
    )
      return

    const arrived = providerById(provider)
    if (arrived) fromProvider.value = arrived
    if (
      typeof reason === 'string' &&
      INVITATION_REASONS.includes(reason) &&
      reason !== 'invitation_required'
    ) {
      refuseKey(KEY_ERRORS[reason])
    }
    router.replace({ query: {} })

    if (typeof linkKey === 'string' && linkKey !== '') {
      stage.value = 'form'
      tab.value = 'key'
      key.value = linkKey
      activate()
    }
  },
  { immediate: true }
)
</script>

<style scoped lang="scss">
.early {
  position: relative;
  overflow: hidden;
  background: #1b1530;
  color: #1b1530;
}

.early__flood {
  position: absolute;
  inset: 0;
  overflow: hidden;
  background: #4fb2ff;
  animation: early-flood 0.8s cubic-bezier(0.5, 0, 0.2, 1) 0.1s both;
}

.early__flood-shade {
  position: absolute;
  inset: 0 0 auto;
  height: 60%;
  background: #1c86e0;
  opacity: 0.45;
  mask-image: linear-gradient(to top, transparent, #000);
}

.early__flood-glow {
  position: absolute;
  left: 50%;
  top: 4%;
  width: 480px;
  height: 480px;
  margin-left: -240px;
  background: radial-gradient(
    circle,
    rgba(255, 255, 255, 0.35),
    transparent 62%
  );
}

.early__confetti {
  position: absolute;
  inset: 0;
  z-index: 10;
  pointer-events: none;
}

.early__column {
  position: relative;
  max-width: 460px;
  min-height: 100vh;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
}

.early__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 16px 0;
}

.early__back {
  color: #1b1530;
  font-weight: 700;
  font-size: 14px;
  text-decoration: none;
}

.early__top-spacer {
  width: 90px;
}

.early__pill {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 30px;
  padding: 0 12px;
  border-radius: 999px;
  background: #1b1530;
  color: #fff;
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.06em;
  animation: early-pop 0.5s cubic-bezier(0.34, 2, 0.64, 1) 0.15s both;
}

.early__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #c6f432;
  animation: early-dot 1.4s ease-in-out infinite;
}

.early__stage {
  position: relative;
  height: 220px;
  display: flex;
  justify-content: center;
  align-items: flex-end;
}

.early__rings {
  position: absolute;
  left: 50%;
  bottom: 4px;
  width: 200px;
  height: 200px;
  margin-left: -100px;
  pointer-events: none;
  animation: early-fade 0.4s ease 0.6s both;
}

.early__ring-disc {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: #1c86e0;
  opacity: 0.7;
}

.early__ring-dash {
  position: absolute;
  inset: -16px;
  border-radius: 50%;
  border: 3px dashed rgba(255, 255, 255, 0.45);
  animation: early-spin 18s linear infinite;

  .early__rings--fast & {
    animation-duration: 1.2s;
  }
}

.early__ring {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  border: 4px solid #fff;
  animation: early-ring 2.4s ease-out infinite;
}

.early__prof {
  position: relative;
  height: 212px;
  max-width: 200px;
  object-fit: contain;
  object-position: center bottom;
  transform-origin: 50% 100%;
  animation:
    early-rise 0.75s cubic-bezier(0.34, 1.7, 0.64, 1) 0.6s both,
    early-bob 2.4s ease-in-out 1.4s infinite;
}

.early__title {
  margin: 6px 0 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 46px;
  line-height: 1;
  letter-spacing: -0.02em;
}

.early__line {
  display: flex;
  justify-content: center;
  color: #1b1530;

  &--light {
    color: #fff;
  }
}

.early__ch {
  display: inline-block;
  white-space: pre;
  animation: early-letter 0.6s cubic-bezier(0.34, 1.9, 0.64, 1) both;
}

.early__sheet {
  flex: 1;
  margin-top: 18px;
  padding: 20px 18px 30px;
  border-radius: 30px 30px 0 0;
  background: var(--cm-surface);
  color: var(--cm-ink);
  animation: early-sheet 0.7s cubic-bezier(0.34, 1.3, 0.64, 1) 1s both;
}

.early__tabs {
  position: relative;
  display: grid;
  grid-template-columns: 1fr 1fr;
  height: 48px;
  padding: 4px;
  box-sizing: border-box;
  border-radius: 16px;
  background: var(--cm-subtle);
}

.early__thumb {
  position: absolute;
  top: 4px;
  bottom: 4px;
  left: 4px;
  width: calc(50% - 4px);
  border-radius: 12px;
  background: #1b1530;
  transition: transform 0.5s cubic-bezier(0.34, 1.45, 0.64, 1);

  &--right {
    transform: translateX(100%);
  }
}

.early__tab {
  position: relative;
  border: 0;
  background: none;
  color: var(--cm-ink);
  font: inherit;
  font-weight: 800;
  font-size: 14px;
  cursor: pointer;
  transition: color 0.3s;

  &--on {
    color: #fff;
  }
}

.early__lead {
  margin: 0;
}

.early__key {
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: 15px;
  letter-spacing: 0.04em;
}

.early__key-msg {
  min-height: 18px;
  margin: 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--cm-muted);

  &--ok {
    color: #0f6bba;
  }

  &--error {
    color: #c02670;
  }
}

.early__shake {
  animation: early-shake 0.45s ease-out;
}

.early__cta {
  height: 58px;
  font-weight: 800;
  font-size: 16px;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  box-shadow: 0 4px 0 var(--cm-ink-soft);

  &:disabled {
    box-shadow: 0 4px 0 var(--cm-dash);
  }
}

.early__honeypot {
  position: absolute;
  left: -10000px;
  width: 1px;
  height: 1px;
  opacity: 0;
}

.early__stamp-row {
  display: flex;
  align-items: center;
  gap: 14px;
}

.early__stamp {
  flex: none;
  width: 52px;
  height: 52px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: #c6f432;
  color: #1b1530;
  font-size: 28px;
  font-weight: 800;
  animation: early-stamp 0.55s cubic-bezier(0.34, 1.8, 0.64, 1) both;

  &--big {
    width: 76px;
    height: 76px;
    font-size: 36px;
  }
}

.early__center {
  align-items: center;
  text-align: center;

  .auth-btn {
    align-self: stretch;
  }
}

.early__ticket {
  position: relative;
  width: 250px;
  height: 120px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  border-radius: 20px;
  background: #1b1530;
  color: #fff;
  transform: rotate(-3deg);
  animation: early-ticket 0.7s cubic-bezier(0.34, 1.6, 0.64, 1) both;

  &::before,
  &::after {
    content: '';
    position: absolute;
    top: 50%;
    width: 24px;
    height: 24px;
    margin-top: -12px;
    border-radius: 50%;
    background: var(--cm-surface);
  }

  &::before {
    left: -12px;
  }

  &::after {
    right: -12px;
  }
}

.early__ticket-kicker {
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.08em;
  color: #4fb2ff;
}

.early__ticket-big {
  font-size: 52px;
  line-height: 1.1;
}

.early__ticket-stamp {
  position: absolute;
  right: -10px;
  top: -14px;
  height: 28px;
  padding: 0 10px;
  display: flex;
  align-items: center;
  border-radius: 999px;
  background: #c6f432;
  color: #1b1530;
  font-size: 12px;
  font-weight: 800;
  animation: early-stamp 0.5s cubic-bezier(0.34, 1.8, 0.64, 1) 0.9s both;
}

@media (min-width: 600px) {
  .early__column {
    min-height: 0;
    padding-bottom: 40px;
  }

  .early__sheet {
    flex: none;
    border-radius: 30px;
  }
}

@keyframes early-flood {
  from {
    clip-path: circle(0% at 50% 30%);
  }
  to {
    clip-path: circle(130% at 50% 30%);
  }
}

@keyframes early-pop {
  from {
    transform: scale(0) rotate(-20deg);
  }
}

@keyframes early-fade {
  from {
    opacity: 0;
  }
}

@keyframes early-rise {
  from {
    transform: translateY(320px) scale(0.6);
  }
}

@keyframes early-bob {
  0%,
  100% {
    transform: translateY(0) rotate(0);
  }
  50% {
    transform: translateY(-8px) rotate(-1.5deg);
  }
}

@keyframes early-letter {
  from {
    opacity: 0;
    transform: translateY(-80px) rotate(var(--tilt)) scale(0.5);
  }
}

@keyframes early-sheet {
  from {
    transform: translateY(420px);
  }
}

@keyframes early-ring {
  from {
    transform: scale(0.55);
    opacity: 0.7;
  }
  to {
    transform: scale(1.7);
    opacity: 0;
  }
}

@keyframes early-spin {
  to {
    transform: rotate(360deg);
  }
}

@keyframes early-dot {
  0%,
  100% {
    transform: scale(1);
    opacity: 1;
  }
  50% {
    transform: scale(1.6);
    opacity: 0.4;
  }
}

@keyframes early-shake {
  20% {
    transform: translateX(-9px);
  }
  40% {
    transform: translateX(8px);
  }
  60% {
    transform: translateX(-6px);
  }
  80% {
    transform: translateX(4px);
  }
}

@keyframes early-stamp {
  0% {
    transform: scale(2.2) rotate(-25deg);
    opacity: 0;
  }
  60% {
    transform: scale(0.9) rotate(-10deg);
    opacity: 1;
  }
}

@keyframes early-ticket {
  from {
    transform: translateY(260px) rotate(12deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .early *,
  .early__flood {
    animation: none !important;
  }
}
</style>
