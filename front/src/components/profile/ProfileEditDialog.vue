<template>
  <q-dialog
    :model-value="modelValue"
    :position="$q.screen.lt.md ? 'bottom' : 'standard'"
    @update:model-value="emit('update:modelValue', $event)"
    @before-show="reset"
  >
    <div class="profile-edit" data-testid="profile-edit">
      <div class="profile-edit__grip lt-md" />
      <h2 class="profile-edit__title">Modifier le profil</h2>

      <div class="profile-edit__field">
        <div class="profile-edit__label">Avatar</div>
        <div class="profile-edit__avatars">
          <button
            v-for="avatar in AVATARS"
            :key="avatar.value"
            type="button"
            class="profile-edit__avatar"
            :class="{
              'profile-edit__avatar--on': avatar.value === draft.avatar
            }"
            :style="{ background: avatar.color }"
            :aria-label="avatar.label"
            :aria-pressed="avatar.value === draft.avatar"
            :data-testid="`avatar-${avatar.value}`"
            @click="draft.avatar = avatar.value"
            >{{ avatar.glyph }}</button
          >
        </div>
      </div>

      <label class="profile-edit__field">
        <span class="profile-edit__label">Nom affiché</span>
        <input
          v-model="draft.displayName"
          class="profile-edit__input"
          maxlength="40"
          :placeholder="fallbackName"
          data-testid="profile-edit-name"
        />
      </label>

      <label class="profile-edit__field">
        <span class="profile-edit__label">Nom d’utilisateur</span>
        <input
          :value="draft.handle"
          class="profile-edit__input"
          autocapitalize="none"
          spellcheck="false"
          placeholder="lea_echecs"
          data-testid="profile-edit-handle"
          @input="onHandle"
        />
        <span
          class="profile-edit__hint"
          :class="`profile-edit__hint--${status.tone}`"
          data-testid="profile-edit-handle-hint"
          >{{ status.text }}</span
        >
      </label>

      <q-banner v-if="error" class="profile-error" rounded>{{
        error
      }}</q-banner>

      <div class="profile-edit__actions">
        <button
          type="button"
          class="profile-edit__btn"
          @click="emit('update:modelValue', false)"
          >Annuler</button
        >
        <button
          type="button"
          class="profile-edit__btn profile-edit__btn--strong"
          :disabled="!status.savable || saving"
          data-testid="profile-edit-save"
          @click="save"
          >Enregistrer</button
        >
      </div>
    </div>
  </q-dialog>
</template>

<script setup>
/**
 * Profile: display name, handle and avatar (design "Profil", "Modifier le profil"). A bottom
 * sheet on a phone, a centred dialog above. The handle is cleaned while typed and checked with
 * the API after 400 ms of stillness; the API decides again on save (409 if taken meanwhile).
 */
import { computed, onBeforeUnmount, reactive, ref } from 'vue'
import { useQuasar } from 'quasar'
import { useAuthStore } from '@/stores/auth'
import { profileApi } from '@/services/api'
import { apiErrorMessage } from '@/utils/apiError'
import { AVATARS, cleanHandle, handleStatus } from '@/utils/profile'

const props = defineProps({
  /** Shown or not (v-model). */
  modelValue: { type: Boolean, required: true },
  /** The /api/profile payload. */
  profile: { type: Object, required: true },
  /** The name shown when no display name is chosen (placeholder). */
  fallbackName: { type: String, required: true }
})
const emit = defineEmits(['update:modelValue'])

const $q = useQuasar()
const auth = useAuthStore()

const draft = reactive({ displayName: '', handle: '', avatar: null })
/** @type {import('vue').Ref<null|'checking'|{handle: string, available: boolean, reason: string|null}>} */
const check = ref(null)
const saving = ref(false)
const error = ref('')
let timer = 0

const status = computed(() =>
  handleStatus(draft.handle, props.profile.handle, check.value)
)

/** Starts from the saved values each time the dialog opens. */
function reset() {
  draft.displayName = props.profile.displayName ?? ''
  draft.handle = props.profile.handle ?? ''
  draft.avatar = props.profile.avatar
  check.value = null
  error.value = ''
}

/** @param {Event} event */
function onHandle(event) {
  const input = /** @type {HTMLInputElement} */ (event.target)
  draft.handle = cleanHandle(input.value)
  input.value = draft.handle
  clearTimeout(timer)
  check.value = null
  if (draft.handle.length < 3 || draft.handle === props.profile.handle) return

  check.value = 'checking'
  const asked = draft.handle
  timer = window.setTimeout(async () => {
    try {
      const answer = await profileApi.handleAvailability(asked)
      // An answer for a handle typed since is ignored.
      if (answer.handle === draft.handle) check.value = answer
    } catch (e) {
      check.value = null
      error.value = apiErrorMessage(e)
    }
  }, 400)
}

async function save() {
  saving.value = true
  error.value = ''
  try {
    await auth.setInfo({
      displayName: draft.displayName.trim() || null,
      handle: draft.handle || null,
      avatar: draft.avatar
    })
    emit('update:modelValue', false)
    $q.notify({ message: 'Profil mis à jour', timeout: 2000 })
  } catch (e) {
    error.value = apiErrorMessage(e, {
      409: 'Ce pseudo vient d’être pris : choisis-en un autre.',
      422: 'Ce pseudo ne peut pas être utilisé.'
    })
  } finally {
    saving.value = false
  }
}

onBeforeUnmount(() => clearTimeout(timer))
</script>

<style scoped lang="scss">
.profile-edit {
  display: flex;
  flex-direction: column;
  gap: 16px;
  width: 100%;
  padding: 10px 20px 34px;
  border-radius: 28px 28px 0 0;
  background: var(--cm-surface);
  color: var(--cm-ink);

  @media (min-width: $breakpoint-md-min) {
    width: 460px;
    max-width: 92vw;
    padding: 26px;
    border-radius: 28px;
  }
}

.profile-edit__grip {
  align-self: center;
  width: 40px;
  height: 5px;
  border-radius: 5px;
  background: var(--cm-line);
}

.profile-edit__title {
  margin: 0;
  font-size: 21px;
  line-height: 1.2;

  @media (min-width: $breakpoint-md-min) {
    font-size: 23px;
  }
}

.profile-edit__field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.profile-edit__label {
  font-weight: 700;
  font-size: 13px;
}

.profile-edit__avatars {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 8px;

  @media (min-width: $breakpoint-md-min) {
    gap: 10px;
  }
}

.profile-edit__avatar {
  aspect-ratio: 1;
  border: none;
  border-radius: 14px;
  color: #1b1530;
  font-size: 26px;
  cursor: pointer;

  &--on {
    box-shadow:
      0 0 0 3px var(--cm-surface),
      0 0 0 5px var(--cm-brand);
  }

  &:focus-visible {
    outline: 2px solid var(--cm-brand);
    outline-offset: 4px;
  }

  @media (min-width: $breakpoint-md-min) {
    border-radius: 16px;
    font-size: 30px;
  }
}

.profile-edit__input {
  height: 46px;
  padding: 0 14px;
  border: none;
  border-radius: 14px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font: inherit;
  font-size: 15px;

  &:focus {
    outline: 2px solid var(--cm-brand);
  }
}

.profile-edit__hint {
  font-size: 12px;

  &--muted {
    color: var(--cm-muted);
  }
  &--ok {
    color: var(--cm-lime-ink);
  }
  &--error {
    color: var(--cm-danger);
  }
}

.profile-edit__actions {
  display: flex;
  gap: 10px;
}

.profile-edit__btn {
  flex: 1;
  height: 48px;
  border: none;
  border-radius: 15px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font: inherit;
  font-weight: 700;
  font-size: 15px;
  cursor: pointer;

  &--strong {
    background: var(--cm-ink);
    color: var(--cm-surface);
  }

  &:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
}
</style>
