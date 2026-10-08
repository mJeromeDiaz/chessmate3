<template>
  <div class="auth-field">
    <label class="auth-label" for="new-password">Mot de passe</label>
    <PasswordField
      id="new-password"
      v-model="model"
      autocomplete="new-password"
      :placeholder="`${PASSWORD_MIN_LENGTH} caractères minimum`"
      data-testid="new-password"
    />
    <div
      class="new-password__meter"
      :aria-label="`Solidité : ${strength.label || 'aucune'}`"
    >
      <div
        v-for="bar in 4"
        :key="bar"
        class="new-password__bar"
        :style="{
          background: bar <= strength.score ? COLORS[strength.score] : undefined
        }"
      />
      <span class="new-password__label">{{ strength.label }}</span>
    </div>
    <p class="new-password__hint">
      {{ PASSWORD_MIN_LENGTH }} caractères minimum. Une phrase de passe est
      idéale.
    </p>
  </div>

  <div class="auth-field">
    <label class="auth-label" for="new-password-confirmation"
      >Confirme le mot de passe</label
    >
    <input
      id="new-password-confirmation"
      v-model="confirmation"
      type="password"
      class="auth-input"
      :class="{
        'auth-input--error': match === false,
        'auth-input--ok': match === true
      }"
      autocomplete="new-password"
      placeholder="Saisis-le à nouveau"
      data-testid="new-password-confirmation"
    />
    <p
      v-if="match !== null"
      class="new-password__match"
      :class="{ 'new-password__match--ok': match }"
      >{{ match ? '✓ Identiques' : 'Pas encore identiques' }}</p
    >
  </div>
</template>

<script setup>
/**
 * A new password and its confirmation, with a strength meter (design "Connexion"). The meter is a
 * hint: the API checks the length, the strength and known leaks itself.
 */
import { computed } from 'vue'
import PasswordField from '@/components/auth/PasswordField.vue'
import { PASSWORD_MIN_LENGTH, passwordStrength } from '@/utils/auth/authFlow'

const model = defineModel({ type: String, default: '' })
const confirmation = defineModel('confirmation', { type: String, default: '' })

const COLORS = ['', '#FF6FAE', '#FFD43B', '#A6D61E', '#5A7A00']

const strength = computed(() => passwordStrength(model.value))
/** null until the confirmation is typed. */
const match = computed(() =>
  confirmation.value ? confirmation.value === model.value : null
)
</script>

<style scoped lang="scss">
.new-password__meter {
  display: flex;
  align-items: center;
  gap: 4px;
}

.new-password__bar {
  flex: 1;
  height: 5px;
  border-radius: 5px;
  background: var(--cm-line);
  transition: background 0.2s;
}

.new-password__label {
  min-width: 52px;
  margin-left: 6px;
  font-size: 12px;
  font-weight: 700;
  color: var(--cm-muted);
  text-align: right;
}

.new-password__hint {
  margin: 0;
  font-size: 12.5px;
  color: var(--cm-muted);
}

.new-password__match {
  margin: 0;
  font-size: 13px;
  font-weight: 600;
  color: #c02670;

  &--ok {
    color: var(--cm-lime-ink);
  }
}
</style>
