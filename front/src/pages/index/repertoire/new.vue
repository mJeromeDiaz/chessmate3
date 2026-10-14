<template>
  <q-page padding>
    <q-form class="repertoire-form q-gutter-md" @submit="submit">
      <div class="text-h5">{{ $t('repertoire.new.title') }}</div>

      <q-input
        v-model="name"
        :label="$t('repertoire.new.name')"
        :maxlength="NAME_MAX"
        autofocus
        :rules="[v => !!v?.trim() || $t('repertoire.new.nameRequired')]"
        data-testid="repertoire-name"
      />

      <div>
        <div class="text-subtitle2 q-mb-xs">{{
          $t('repertoire.new.color')
        }}</div>
        <q-btn-toggle
          v-model="color"
          no-caps
          unelevated
          toggle-color="primary"
          :options="[
            { label: $t('repertoire.list.white'), value: 'white' },
            { label: $t('repertoire.list.black'), value: 'black' }
          ]"
          data-testid="repertoire-color"
        />
        <div class="text-caption text-grey q-mt-xs">
          {{ $t('repertoire.new.colorHint') }}
        </div>
      </div>

      <q-banner v-if="error" rounded class="bg-negative text-white">{{
        error
      }}</q-banner>

      <div class="row q-gutter-sm">
        <q-btn
          type="submit"
          unelevated
          class="cm-btn-create"
          no-caps
          :label="$t('repertoire.new.create')"
          :loading="saving"
          data-testid="repertoire-create"
        />
        <q-btn
          flat
          no-caps
          :label="$t('common.actions.cancel')"
          to="/repertoire"
        />
      </div>
    </q-form>
  </q-page>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useRepertoireStore } from '@/stores/repertoire'
import { apiErrorMessage } from '@/utils/apiError'
import { t } from '@/i18n'

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
      422: t('repertoire.new.refused')
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
