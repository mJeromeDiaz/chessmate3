<template>
  <q-card flat bordered>
    <q-card-section>
      <div class="text-subtitle1">Mot de passe</div>

      <!-- Change: the current password is required. -->
      <q-form v-if="profile.hasPassword" class="q-gutter-md" @submit="change">
        <q-input
          v-model="current"
          type="password"
          label="Mot de passe actuel"
          autocomplete="current-password"
          outlined
          :rules="[required]"
        />
        <q-input
          v-model="next"
          type="password"
          label="Nouveau mot de passe"
          autocomplete="new-password"
          hint="12 caractères minimum. Vos autres sessions et appareils de confiance seront révoqués."
          outlined
          :rules="[v => (v && v.length >= 12) || '12 caractères minimum']"
        />
        <q-btn
          type="submit"
          color="primary"
          no-caps
          label="Changer le mot de passe"
          :loading="loading"
        />
      </q-form>

      <!-- Add: for accounts created with Google/Lichess. -->
      <q-form v-else class="q-gutter-md" @submit="add">
        <p>
          Ajoutez un mot de passe pour vous connecter aussi avec votre email. Un
          code de vérification vous sera envoyé par email à chaque connexion.
        </p>
        <q-input
          v-if="needsEmail"
          v-model="email"
          type="email"
          label="Email"
          autocomplete="email"
          hint="Nous vous enverrons un lien pour la confirmer."
          outlined
          :rules="[required]"
        />
        <q-input
          v-model="next"
          type="password"
          label="Mot de passe"
          autocomplete="new-password"
          hint="12 caractères minimum."
          outlined
          :rules="[v => (v && v.length >= 12) || '12 caractères minimum']"
        />
        <q-btn
          type="submit"
          color="primary"
          no-caps
          label="Ajouter un mot de passe"
          :loading="loading"
        />
      </q-form>

      <q-banner v-if="success" class="bg-green-1 q-mt-md" rounded>{{
        success
      }}</q-banner>
      <q-banner v-if="error" class="bg-red-1 q-mt-md" rounded>{{
        error
      }}</q-banner>
    </q-card-section>
  </q-card>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'

const props = defineProps({
  /** The /api/profile payload. */
  profile: { type: Object, required: true }
})

const auth = useAuthStore()
const current = ref('')
const next = ref('')
const email = ref('')
const loading = ref(false)
const error = ref('')
const success = ref('')

const required = v => !!v || 'Champ requis'
const needsEmail = computed(
  () => !props.profile.email || !props.profile.emailVerified
)

async function change() {
  await run(
    async () => {
      await auth.changePassword(current.value, next.value)
      success.value =
        'Mot de passe modifié. Vos autres sessions ont été fermées.'
    },
    { 400: 'Mot de passe actuel incorrect.' }
  )
}

async function add() {
  await run(
    async () => {
      const status = await auth.addPassword(
        next.value,
        needsEmail.value ? email.value : null
      )
      success.value =
        status === 'added'
          ? 'Mot de passe ajouté.'
          : 'Si cette adresse peut être utilisée, un lien de confirmation vient de lui être envoyé. Votre mot de passe fonctionnera une fois l’adresse confirmée.'
    },
    { 409: 'Ce compte a déjà un mot de passe.' }
  )
}

/**
 * @param {() => Promise<void>} task
 * @param {Record<number, string>} messages
 */
async function run(task, messages) {
  loading.value = true
  error.value = ''
  success.value = ''

  try {
    await task()
  } catch (e) {
    error.value = apiErrorMessage(e, messages)
  } finally {
    loading.value = false
    current.value = ''
    next.value = ''
  }
}
</script>
