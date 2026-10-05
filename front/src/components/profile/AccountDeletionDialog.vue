<template>
  <q-dialog
    :model-value="modelValue"
    persistent
    @update:model-value="emit('update:modelValue', $event)"
  >
    <q-card class="deletion" data-testid="deletion-dialog">
      <q-card-section>
        <h2 class="cm-card__title">Supprimer mon compte</h2>
      </q-card-section>

      <q-card-section v-if="step === 'intro'" class="q-pt-none">
        <p>Seront effacés définitivement :</p>
        <ul class="deletion__list">
          <li>ton classement, tes puzzles et tes sets Woodpecker ;</li>
          <li>tes répertoires et leurs révisions ;</li>
          <li>tes séances, sessions et statistiques ;</li>
          <li>ton profil et tes comptes liés.</li>
        </ul>
        <p>
          Ton compte sera <strong>gelé pendant 30 jours</strong> : tu seras
          déconnecté partout, et pourras annuler en te reconnectant. Ensuite, la
          suppression est définitive.
        </p>
        <p class="cm-muted">
          Pense à exporter tes données d’abord (bouton « Exporter »).
        </p>
        <q-checkbox
          v-model="understood"
          label="Je comprends que mes données seront supprimées"
          data-testid="deletion-understood"
        />
      </q-card-section>

      <q-card-section v-else-if="step === 'code'" class="q-pt-none">
        <p>
          Un code à 6 chiffres vient d’être envoyé à
          <strong>{{ auth.profile?.email }}</strong
          >. Il est valable 10 minutes.
        </p>
        <q-input
          v-model="code"
          outlined
          autofocus
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          label="Code reçu par email"
          data-testid="deletion-code"
          @keyup.enter="confirm"
        />
        <q-btn
          flat
          dense
          no-caps
          color="primary"
          class="q-mt-sm"
          label="Renvoyer le code"
          :disable="busy"
          data-testid="deletion-resend"
          @click="start"
        />
      </q-card-section>

      <q-card-section v-else-if="step === 'recent'" class="q-pt-none">
        <p>
          Ton compte n’a pas d’adresse email : ta connexion récente suffit à
          confirmer.
        </p>
      </q-card-section>

      <q-card-section v-else class="q-pt-none" data-testid="deletion-relogin">
        <p>
          Ton compte n’a pas d’adresse email : pour confirmer, reconnecte-toi
          (avec Lichess), puis reviens supprimer ton compte depuis ton profil
          dans les 10 minutes.
        </p>
      </q-card-section>

      <q-card-section v-if="error" class="q-pt-none">
        <div class="deletion__error" data-testid="deletion-error">{{
          error
        }}</div>
      </q-card-section>

      <q-card-actions align="right">
        <q-btn
          flat
          no-caps
          label="Annuler"
          :disable="busy"
          data-testid="deletion-close"
          @click="emit('update:modelValue', false)"
        />
        <q-btn
          v-if="step === 'intro'"
          unelevated
          no-caps
          color="negative"
          label="Continuer"
          :loading="busy"
          :disable="!understood"
          data-testid="deletion-start"
          @click="start"
        />
        <q-btn
          v-else-if="step === 'code' || step === 'recent'"
          unelevated
          no-caps
          color="negative"
          label="Supprimer mon compte"
          :loading="busy"
          :disable="step === 'code' && !/^\d{6}$/.test(code)"
          data-testid="deletion-confirm"
          @click="confirm"
        />
        <q-btn
          v-else
          unelevated
          no-caps
          color="primary"
          label="Me reconnecter"
          data-testid="deletion-signin"
          @click="signInAgain"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
/**
 * Account deletion (docs/AUTH.md): what is erased and the 30 frozen days, then the proof: the
 * emailed code, or, for an account without an email, a sign-in less than 10 minutes old (signing
 * in again otherwise). Once confirmed, every session is closed: the deletion page says when the
 * account will be purged.
 */
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { profileApi } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { deletionRefusal } from '@/utils/profile'
import { FROZEN_PAGE } from '@/router/guards'

const props = defineProps({
  modelValue: { type: Boolean, default: false }
})

const emit = defineEmits({
  'update:modelValue': value => typeof value === 'boolean'
})

const auth = useAuthStore()
const router = useRouter()

/** @type {import('vue').Ref<'intro'|'code'|'recent'|'relogin'>} */
const step = ref('intro')
const understood = ref(false)
const code = ref('')
const busy = ref(false)
const error = ref('')

watch(
  () => props.modelValue,
  open => {
    if (!open) return
    step.value = 'intro'
    understood.value = false
    code.value = ''
    error.value = ''
  }
)

/** Sends the code, or checks the sign-in of an account without an email. */
async function start() {
  busy.value = true
  error.value = ''
  try {
    const data = await profileApi.startDeletion()
    if (data.method === 'email') step.value = 'code'
    else step.value = data.recentSignIn ? 'recent' : 'relogin'
  } catch (e) {
    error.value = deletionRefusal(e).message
  } finally {
    busy.value = false
  }
}

async function confirm() {
  if (busy.value) return
  busy.value = true
  error.value = ''
  try {
    const at = await auth.confirmDeletion(
      step.value === 'code' ? code.value : null
    )
    emit('update:modelValue', false)
    await router.replace({ path: FROZEN_PAGE, query: { at } })
  } catch (e) {
    const refusal = deletionRefusal(e)
    if (refusal.signInAgain) step.value = 'relogin'
    else error.value = refusal.message
  } finally {
    busy.value = false
  }
}

async function signInAgain() {
  emit('update:modelValue', false)
  await auth.logout()
  await router.push({ path: '/login', query: { redirect: '/profile' } })
}
</script>

<style scoped lang="scss">
.deletion {
  width: 480px;
  max-width: 94vw;
  border-radius: 22px;
}

.deletion__list {
  margin: 0 0 12px;
  padding-left: 20px;
}

.deletion__error {
  color: var(--cm-danger);
  font-size: 13px;
}
</style>
