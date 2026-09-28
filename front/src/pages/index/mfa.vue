<template>
  <q-page padding class="row justify-center">
    <div class="col-12 col-sm-8 col-md-5">
      <div class="text-h5 q-mb-md">Code de vérification</div>
      <p>
        Nous avons envoyé un code à 6 chiffres à
        <strong>{{ auth.mfa?.email }}</strong
        >. Il est valable 10 minutes.
      </p>

      <q-form class="q-gutter-md" @submit="submit">
        <q-input
          v-model="code"
          label="Code"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          outlined
          autofocus
          :rules="[v => /^\d{6}$/.test(v) || '6 chiffres']"
        />
        <q-checkbox
          v-model="trustDevice"
          label="Faire confiance à cet appareil pendant 30 jours"
        />

        <q-banner v-if="error" class="bg-red-1" rounded>{{ error }}</q-banner>
        <q-banner v-if="info" class="bg-green-1" rounded>{{ info }}</q-banner>

        <div class="row q-gutter-sm">
          <q-btn
            type="submit"
            color="primary"
            no-caps
            label="Valider"
            :loading="loading"
          />
          <q-btn
            flat
            no-caps
            :label="
              cooldown > 0
                ? `Renvoyer le code (${cooldown} s)`
                : 'Renvoyer le code'
            "
            :disable="cooldown > 0"
            :loading="resending"
            @click="resend"
          />
          <q-btn flat no-caps label="Annuler" @click="cancel" />
        </div>
      </q-form>
    </div>
  </q-page>
</template>

<script setup>
import { onBeforeUnmount, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { safeRedirect } from '@/router/guards'
import { apiErrorMessage } from '@/utils/apiError'

definePage({ meta: { auth: 'mfa' } })

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
  loading.value = true
  error.value = ''
  info.value = ''

  try {
    await auth.verifyMfa(code.value, trustDevice.value)
    router.replace(safeRedirect(route.query.redirect))
  } catch (e) {
    error.value = apiErrorMessage(e, {
      401: 'Code invalide ou expiré. Après 5 essais, il faut recommencer la connexion.'
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
  } catch (e) {
    if (e?.response?.status === 401) {
      // The pending login is over (expired or locked): start again.
      auth.cancelMfa()
      router.replace('/login')
      return
    }
    error.value = apiErrorMessage(e, {
      429: 'Patientez avant de demander un nouveau code.'
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
