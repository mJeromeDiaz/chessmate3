<template>
  <q-page padding>
    <q-form class="repertoire-form q-gutter-md" @submit="submit">
      <div class="text-h5">Nouveau répertoire</div>

      <q-input
        v-model="name"
        label="Nom"
        :maxlength="NAME_MAX"
        autofocus
        :rules="[v => !!v?.trim() || 'Nom requis']"
        data-testid="repertoire-name"
      />

      <div>
        <div class="text-subtitle2 q-mb-xs">Couleur</div>
        <q-btn-toggle
          v-model="color"
          no-caps
          unelevated
          toggle-color="primary"
          :options="[
            { label: 'Blancs', value: 'white' },
            { label: 'Noirs', value: 'black' }
          ]"
          data-testid="repertoire-color"
        />
        <div class="text-caption text-grey q-mt-xs">
          Vos coups sont ceux de cette couleur : un seul par position, celui qui
          sera testé. Les coups de l’adversaire peuvent être aussi nombreux que
          vous voulez.
        </div>
      </div>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>

      <div class="row q-gutter-sm">
        <q-btn
          type="submit"
          color="primary"
          no-caps
          label="Créer et commencer"
          :loading="saving"
          data-testid="repertoire-create"
        />
        <q-btn flat no-caps label="Annuler" to="/repertoire" />
      </div>
    </q-form>
  </q-page>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useRepertoireStore } from '@/stores/repertoire'
import { apiErrorMessage } from '@/utils/apiError'

definePage({ meta: { auth: 'required' } })

/** Same limit as the API (App\Repertoire\Limits). */
const NAME_MAX = 80

const router = useRouter()
const store = useRepertoireStore()
const name = ref('')
/** @type {import('vue').Ref<'white'|'black'>} */
const color = ref('white')
const saving = ref(false)
const error = ref('')

async function submit() {
  saving.value = true
  error.value = ''
  try {
    const created = await store.create({
      name: name.value.trim(),
      color: color.value
    })
    router.push(`/repertoire/${created.id}`)
  } catch (e) {
    error.value = apiErrorMessage(e, {
      422: 'Vous avez atteint le nombre maximal de répertoires, ou le nom est invalide.'
    })
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.repertoire-form {
  max-width: 600px;
  margin: 0 auto;
}
</style>
