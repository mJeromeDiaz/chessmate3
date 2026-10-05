<template>
  <q-dialog :model-value="!!invitation" persistent @update:model-value="close">
    <q-card v-if="invitation" class="key-dialog" data-testid="key-dialog">
      <q-card-section>
        <div class="text-h6">{{ title }}</div>
        <p class="q-mt-sm q-mb-none">
          L’email part vers <strong>{{ invitation.email }}</strong
          >. Vous pouvez aussi transmettre ce lien par un autre moyen.
        </p>
      </q-card-section>

      <q-card-section class="q-pt-none">
        <q-banner class="cm-banner--warning" rounded dense>
          La clé ne sera plus jamais affichée : le serveur n’en garde que
          l’empreinte.
        </q-banner>

        <label class="key-dialog__label">Lien d’inscription</label>
        <div class="key-dialog__value">
          <code data-testid="key-dialog-link">{{ link }}</code>
          <q-btn
            flat
            dense
            no-caps
            icon="content_copy"
            label="Copier"
            @click="copy(link, 'Lien copié')"
          />
        </div>

        <label class="key-dialog__label">Clé</label>
        <div class="key-dialog__value">
          <code data-testid="key-dialog-key">{{ invitation.key }}</code>
          <q-btn
            flat
            dense
            no-caps
            icon="content_copy"
            label="Copier"
            @click="copy(invitation.key, 'Clé copiée')"
          />
        </div>
      </q-card-section>

      <q-card-actions align="right">
        <q-btn
          color="primary"
          no-caps
          label="J’ai noté"
          data-testid="key-dialog-close"
          @click="close"
        />
      </q-card-actions>
    </q-card>
  </q-dialog>
</template>

<script setup>
import { computed } from 'vue'
import { copyToClipboard, useQuasar } from 'quasar'
import { signupLink } from '@/utils/admin/invitations'

/**
 * Shows a new key once, after a creation or a resend, with its sign-up link and copy buttons
 * (docs/EARLY_ACCESS.md). Closing it forgets the key.
 */
const props = defineProps({
  /** @type {import('vue').PropType<{email: string, key: string}|null>} */
  invitation: { type: Object, default: null },
  /** Creation or resend. */
  resent: { type: Boolean, default: false }
})
const emit = defineEmits(['close'])

const $q = useQuasar()
const title = computed(() =>
  props.resent ? 'Nouvelle clé envoyée' : 'Invitation envoyée'
)
const link = computed(() =>
  props.invitation ? signupLink(props.invitation.key) : ''
)

/**
 * @param {string} text
 * @param {string} done
 */
async function copy(text, done) {
  try {
    await copyToClipboard(text)
    $q.notify({ message: done, timeout: 1500 })
  } catch {
    $q.notify({
      type: 'negative',
      message: 'La copie a échoué : sélectionnez le texte.'
    })
  }
}

function close() {
  emit('close')
}
</script>

<style scoped lang="scss">
.key-dialog {
  width: 560px;
  max-width: 92vw;
}

.key-dialog__label {
  display: block;
  margin-top: 14px;
  font-size: 12px;
  font-weight: 600;
  color: var(--cm-ink-soft);
}

.key-dialog__value {
  display: flex;
  align-items: center;
  gap: 8px;

  code {
    flex: 1;
    padding: 6px 8px;
    overflow-wrap: anywhere;
    background: var(--cm-subtle);
    border-radius: 8px;
    font-size: 13px;
    user-select: all;
  }
}
</style>
