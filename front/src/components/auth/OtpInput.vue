<template>
  <div class="otp" @click="focus">
    <div class="otp__boxes" aria-hidden="true">
      <div
        v-for="(box, i) in boxes"
        :key="i"
        class="otp__box"
        :class="{
          'otp__box--filled': box.ch,
          'otp__box--active': box.active,
          'otp__box--error': error
        }"
        >{{ box.ch }}</div
      >
    </div>
    <input
      ref="input"
      :value="model"
      class="otp__input"
      inputmode="numeric"
      autocomplete="one-time-code"
      maxlength="6"
      aria-label="Code de vérification"
      v-bind="$attrs"
      @input="onInput"
      @focus="focused = true"
      @blur="focused = false"
    />
  </div>
</template>

<script setup>
/**
 * The 6-digit code as six boxes over one transparent input (design "Connexion"): typing, pasting
 * and the phone's one-time-code suggestion all work. Emits `complete` once six digits are in.
 */
import { computed, onMounted, ref } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  error: { type: Boolean, default: false },
  autofocus: { type: Boolean, default: true }
})
const emit = defineEmits(['complete'])
const model = defineModel({ type: String, default: '' })

const LENGTH = 6
const input = ref(/** @type {HTMLInputElement|null} */ (null))
const focused = ref(false)

const boxes = computed(() =>
  Array.from({ length: LENGTH }, (_, i) => ({
    ch: model.value[i] ?? '',
    active:
      focused.value &&
      (i === model.value.length ||
        (i === LENGTH - 1 && model.value.length === LENGTH))
  }))
)

/** @param {Event} event */
function onInput(event) {
  const target = /** @type {HTMLInputElement} */ (event.target)
  const digits = target.value.replace(/\D/g, '').slice(0, LENGTH)
  target.value = digits
  model.value = digits
  if (digits.length === LENGTH) emit('complete', digits)
}

function focus() {
  input.value?.focus()
}

onMounted(() => {
  if (props.autofocus) focus()
})

defineExpose({ focus })
</script>

<style scoped lang="scss">
.otp {
  position: relative;
}

.otp__boxes {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 8px;
}

.otp__box {
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid var(--cm-line);
  border-radius: 16px;
  background: var(--cm-page);
  color: var(--cm-ink);
  font-family: var(--cm-heading);
  font-weight: 800;
  font-size: 26px;
  transition: border-color 0.2s;

  &--filled {
    border-color: var(--cm-ink);
    background: var(--cm-surface);
  }

  &--active {
    border-color: var(--cm-brand);
  }

  &--error {
    border-color: #ff6fae;
  }
}

.otp__input {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  opacity: 0;
  border: 0;
  font-size: 16px;
  cursor: text;
}
</style>
