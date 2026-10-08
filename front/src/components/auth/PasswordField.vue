<template>
  <div class="auth-input-wrap">
    <input
      ref="input"
      v-model="model"
      :type="shown ? 'text' : 'password'"
      class="auth-input"
      :class="{ 'auth-input--error': error }"
      :autocomplete="autocomplete"
      :placeholder="placeholder"
      :aria-label="label"
      :aria-invalid="error || undefined"
      v-bind="$attrs"
    />
    <button
      type="button"
      class="auth-input-toggle"
      :aria-pressed="shown"
      @click="shown = !shown"
      >{{ shown ? 'Masquer' : 'Afficher' }}</button
    >
  </div>
</template>

<script setup>
/** A password input with a show/hide toggle (design "Connexion"). */
import { ref } from 'vue'

defineOptions({ inheritAttrs: false })

defineProps({
  autocomplete: { type: String, default: 'current-password' },
  placeholder: { type: String, default: '••••••••••••' },
  label: { type: String, default: 'Mot de passe' },
  error: { type: Boolean, default: false }
})

const model = defineModel({ type: String, default: '' })
const shown = ref(false)
const input = ref(/** @type {HTMLInputElement|null} */ (null))

defineExpose({ focus: () => input.value?.focus() })
</script>
