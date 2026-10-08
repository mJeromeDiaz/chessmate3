<template>
  <AuthShell>
    <form class="auth-step" novalidate @submit.prevent="submit()">
      <button type="button" class="auth-back" @click="cancel">← Retour</button>
      <div>
        <div class="auth-kicker">MOT DE PASSE ✓ · DERNIÈRE ÉTAPE</div>
        <h1 class="auth-title">Vérifie ta boîte mail</h1>
        <p class="auth-lead">
          Un code à 6 chiffres vient d’être envoyé à
          <b>{{ auth.mfa?.email }}</b
          >. Il est valable 10 minutes.
        </p>
      </div>

      <OtpInput
        v-model="code"
        :error="!!error"
        data-testid="mfa-code"
        @complete="submit"
        @update:model-value="error = ''"
      />
      <p v-if="error" class="auth-error mfa__error">{{ error }}</p>
      <div v-if="info" class="auth-note">{{ info }}</div>

      <div class="mfa__resend">
        <span>Rien reçu ? Pense aux spams.</span>
        <button
          type="button"
          class="auth-link"
          :disabled="cooldown > 0 || resending"
          @click="resend"
          >{{
            cooldown > 0 ? `Renvoyer dans ${cooldown} s` : 'Renvoyer le code'
          }}</button
        >
      </div>

      <label class="mfa__trust">
        <input v-model="trustDevice" type="checkbox" data-testid="mfa-trust" />
        Faire confiance à cet appareil pendant 30 jours
      </label>

      <button
        type="submit"
        class="auth-btn"
        :disabled="loading || code.length !== 6"
        data-testid="mfa-submit"
        >{{ loading ? 'Vérification…' : 'Valider' }}</button
      >
    </form>
  </AuthShell>
</template>

<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AuthShell from '@/components/auth/AuthShell.vue'
import OtpInput from '@/components/auth/OtpInput.vue'
import { useAuthStore } from '@/stores/auth'
import { safeRedirect } from '@/router/guards'
import { apiErrorMessage } from '@/utils/apiError'

/**
 * The emailed code, after a right password (docs/AUTH.md): six boxes, sent as soon as the sixth
 * digit is in. A trusted device skips this page for 30 days.
 */
definePage({ meta: { auth: 'mfa', landing: true } })

/** Client-side hint only; the API enforces its own minimum delay between two sends. */
const RESEND_COOLDOWN_SECONDS = 60

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const code = ref('')
const trustDevice = ref(false)
const loading = ref(false)
const resending = ref(false)
const error = ref('')
const info = ref('')
const cooldown = ref(RESEND_COOLDOWN_SECONDS)

const timer = setInterval(() => {
  if (cooldown.value > 0) cooldown.value--
}, 1000)
onBeforeUnmount(() => clearInterval(timer))

async function submit() {
  if (loading.value || code.value.length !== 6) return
  loading.value = true
  error.value = ''
  info.value = ''

  try {
    await auth.verifyMfa(code.value, trustDevice.value)
    router.replace(safeRedirect(route.query.redirect))
  } catch (e) {
    error.value = apiErrorMessage(e, {
      401: 'Code invalide ou expiré. Après 5 essais, il faut recommencer la connexion.',
      423: 'Ce compte est suspendu. Écris-nous depuis la page Contact si tu penses qu’il s’agit d’une erreur.'
    })
    code.value = ''
  } finally {
    loading.value = false
  }
}

async function resend() {
  resending.value = true
  error.value = ''
  info.value = ''

  try {
    await auth.resendMfa()
    info.value =
      'Un nouveau code a été envoyé. Le précédent ne fonctionne plus.'
    cooldown.value = RESEND_COOLDOWN_SECONDS
    code.value = ''
  } catch (e) {
    if (/** @type {any} */ (e)?.response?.status === 401) {
      // The pending login is over (expired or locked): start again.
      auth.cancelMfa()
      router.replace('/login')
      return
    }
    error.value = apiErrorMessage(e, {
      429: 'Patiente avant de demander un nouveau code.'
    })
  } finally {
    resending.value = false
  }
}

function cancel() {
  auth.cancelMfa()
  router.replace('/login')
}
</script>

<style scoped lang="scss">
.mfa__error {
  margin-top: -6px;
}

.mfa__resend {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  font-size: 13.5px;
  color: var(--cm-muted);
}

.mfa__trust {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  color: var(--cm-ink-soft);
  cursor: pointer;

  input {
    width: 18px;
    height: 18px;
    accent-color: var(--cm-brand);
  }
}
</style>
