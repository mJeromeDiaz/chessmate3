<template>
  <q-card flat bordered data-testid="calendar-section">
    <q-card-section>
      <div class="text-subtitle1">Calendrier</div>
      <p class="text-caption text-grey q-mb-sm">
        Une adresse privée à ajouter à ton agenda (Google Agenda, Apple
        Calendrier, Outlook…) : il affiche tes sessions marquées « Intégrer à
        mon calendrier » et suit leurs changements. Garde-la pour toi : qui la
        connaît voit tes sessions.
      </p>

      <q-spinner v-if="loading" />
      <template v-else-if="address.url">
        <q-input
          :model-value="address.url"
          readonly
          dense
          outlined
          label="Adresse de ton calendrier"
          data-testid="calendar-url"
          @focus="$event.target.select()"
        >
          <template #append>
            <q-btn
              flat
              round
              dense
              icon="content_copy"
              aria-label="Copier l'adresse"
              data-testid="calendar-copy"
              @click="copy"
            />
          </template>
        </q-input>
        <p class="text-caption text-grey q-mt-xs q-mb-sm">
          Google Agenda : « Autres agendas » → « À partir de l'URL ». Sur Apple
          ou Outlook, le bouton « S'abonner » ouvre l'application.
        </p>
        <div class="row q-gutter-sm">
          <q-btn
            color="primary"
            unelevated
            no-caps
            icon="event"
            label="S'abonner"
            :href="address.webcalUrl"
            data-testid="calendar-subscribe"
          />
          <q-btn
            flat
            no-caps
            label="Nouvelle adresse"
            :loading="busy === 'regenerate'"
            data-testid="calendar-regenerate"
            @click="confirmRegenerate"
          />
          <q-btn
            flat
            no-caps
            color="negative"
            label="Désactiver"
            :loading="busy === 'revoke'"
            data-testid="calendar-revoke"
            @click="confirmRevoke"
          />
        </div>
      </template>
      <q-btn
        v-else
        color="primary"
        unelevated
        no-caps
        icon="event"
        label="Créer mon adresse de calendrier"
        :loading="busy === 'regenerate'"
        data-testid="calendar-create"
        @click="regenerate"
      />

      <q-banner v-if="error" class="bg-red-1 q-mt-md" rounded>{{
        error
      }}</q-banner>
    </q-card-section>
  </q-card>
</template>

<script setup>
/**
 * Profile: the private address of the user's calendar feed (docs/TRAINING.md, calendar), shown
 * again at each visit, regenerated (the previous one stops working) or revoked.
 */
import { onMounted, ref } from 'vue'
import { copyToClipboard, useQuasar } from 'quasar'
import { calendarApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'

const $q = useQuasar()
/** @type {import('vue').Ref<{url: string|null, webcalUrl: string|null}>} */
const address = ref({ url: null, webcalUrl: null })
const loading = ref(true)
/** @type {import('vue').Ref<null|'regenerate'|'revoke'>} */
const busy = ref(null)
const error = ref('')

async function regenerate() {
  busy.value = 'regenerate'
  error.value = ''
  try {
    address.value = await calendarApi.regenerate()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = null
  }
}

function confirmRegenerate() {
  $q.dialog({
    title: 'Créer une nouvelle adresse ?',
    message:
      "L'adresse actuelle cessera de fonctionner : il faudra ajouter la nouvelle à ton agenda.",
    cancel: true
  }).onOk(regenerate)
}

function confirmRevoke() {
  $q.dialog({
    title: 'Désactiver ton calendrier ?',
    message:
      "L'adresse cessera de fonctionner et ton agenda n'affichera plus tes sessions.",
    cancel: true
  }).onOk(async () => {
    busy.value = 'revoke'
    error.value = ''
    try {
      await calendarApi.revoke()
      address.value = { url: null, webcalUrl: null }
    } catch (e) {
      error.value = apiErrorMessage(e)
    } finally {
      busy.value = null
    }
  })
}

async function copy() {
  try {
    await copyToClipboard(address.value.url ?? '')
    $q.notify({ message: 'Adresse copiée', timeout: 1500 })
  } catch {
    error.value = "La copie a échoué : sélectionne l'adresse pour la copier."
  }
}

onMounted(async () => {
  try {
    address.value = await calendarApi.address()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
})
</script>
