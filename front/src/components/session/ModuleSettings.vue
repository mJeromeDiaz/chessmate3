<template>
  <div class="module-settings" data-testid="module-settings">
    <div
      class="module-settings__hero"
      :style="{ background: module.bg, color: module.ink }"
    >
      <div class="module-settings__kicker">{{ kicker }}</div>
      <div class="module-settings__title cm-heading">{{ module.title }}</div>
      <div class="module-settings__desc">{{ module.desc }}</div>
      <router-link
        v-if="module.profSlug"
        :to="`/prof/${module.profSlug}`"
        class="module-settings__profile"
        >Voir le profil du prof →</router-link
      >
      <ProfAvatar
        class="module-settings__avatar"
        :image="module.image"
        :deep="module.deep"
        :ink="module.ink"
        :glyph="module.glyph"
      />
    </div>

    <div v-if="themesKey" class="module-settings__fields">
      <div class="module-settings__subhead">
        <button
          type="button"
          class="module-settings__subback"
          aria-label="Retour aux réglages"
          @click="themesKey = null"
          >←</button
        >
        <span class="module-settings__label">Thèmes</span>
      </div>
      <ThemePicker
        v-model="values[themesKey]"
        :themes="puzzles.themes"
        :max="MAX_THEMES"
        :soft="module.soft"
        :deep="module.deep"
        :accent-ink="module.accentInk"
      />
    </div>

    <div v-else class="module-settings__fields">
      <div
        v-for="field in module.fields"
        :key="field.key"
        class="module-settings__field"
        :data-testid="`field-${field.key}`"
      >
        <div class="module-settings__field-head">
          <label
            :id="`${uid}-${field.key}`"
            :for="
              field.type === 'slider' || field.type === 'text'
                ? `${uid}-${field.key}-input`
                : undefined
            "
            class="module-settings__label"
            >{{ field.label }}</label
          >
          <span
            class="module-settings__display"
            :style="{ color: module.accentInk }"
            >{{ display(field) }}</span
          >
          <button
            v-if="field.type === 'toggle'"
            type="button"
            role="switch"
            class="module-settings__switch"
            :aria-checked="values[field.key]"
            :aria-labelledby="`${uid}-${field.key}`"
            :style="{
              background: values[field.key] ? module.deep : 'var(--cm-dash)'
            }"
            @click="values[field.key] = !values[field.key]"
          >
            <span
              class="module-settings__knob"
              :class="{ 'module-settings__knob--on': values[field.key] }"
            />
          </button>
        </div>
        <div v-if="field.hint" class="module-settings__hint">{{
          field.hint
        }}</div>
        <input
          v-if="field.type === 'slider'"
          :id="`${uid}-${field.key}-input`"
          v-model.number="values[field.key]"
          type="range"
          class="module-settings__slider"
          :min="field.min"
          :max="field.max"
          :step="field.step"
          :style="{ accentColor: module.deep }"
        />
        <div
          v-else-if="field.type === 'one' || field.type === 'many'"
          class="module-settings__options"
          role="group"
          :aria-labelledby="`${uid}-${field.key}`"
        >
          <button
            v-for="option in field.options"
            :key="option"
            type="button"
            class="module-settings__option"
            :aria-pressed="isOn(field, option)"
            :style="
              isOn(field, option)
                ? { background: module.soft, borderColor: module.deep }
                : {}
            "
            @click="pick(field, option)"
            >{{ option }}</button
          >
        </div>
        <div
          v-else-if="field.type === 'themes'"
          class="module-settings__options"
        >
          <span
            v-for="key in values[field.key]"
            :key="key"
            class="module-settings__tag"
            :style="{ background: module.soft }"
            >{{ puzzles.themeLabel(key) }}</span
          >
          <span
            v-if="!values[field.key].length"
            class="module-settings__tag"
            :style="{ background: module.soft }"
            >Tous les thèmes</span
          >
          <button
            type="button"
            class="module-settings__option"
            data-testid="themes-open"
            @click="themesKey = field.key"
            >Choisir…</button
          >
        </div>
        <template v-else-if="field.type === 'repertoire'">
          <div v-if="!context.loaded" class="module-settings__hint">{{
            session.subjectsError || 'Chargement de tes répertoires…'
          }}</div>
          <div
            v-else-if="!context.repertoires.length"
            class="module-settings__empty"
            >Tu n’as pas encore de répertoire.</div
          >
          <div
            v-else
            class="module-settings__options"
            role="group"
            :aria-labelledby="`${uid}-${field.key}`"
          >
            <button
              v-for="repertoire in context.repertoires"
              :key="repertoire.id"
              type="button"
              class="module-settings__option"
              :aria-pressed="values[field.key].includes(repertoire.id)"
              :style="
                values[field.key].includes(repertoire.id)
                  ? { background: module.soft, borderColor: module.deep }
                  : {}
              "
              @click="toggleId(field.key, repertoire.id)"
            >
              <span
                class="module-settings__piece"
                :class="`module-settings__piece--${repertoire.color}`"
                :title="repertoire.color === 'white' ? 'Blancs' : 'Noirs'"
              />
              {{ repertoire.name }}
            </button>
          </div>
          <router-link
            v-if="context.loaded"
            to="/repertoire/new"
            class="module-settings__link"
            :class="{
              'module-settings__link--cta': !context.repertoires.length
            }"
            :style="
              context.repertoires.length
                ? { color: module.accentInk }
                : { background: module.deep, color: '#fff' }
            "
            data-testid="session-repertoire-create"
            >+ Créer un répertoire</router-link
          >
        </template>
        <template v-else-if="field.type === 'lightSet'">
          <div v-if="!context.loaded" class="module-settings__hint">{{
            session.subjectsError || 'Chargement de ton set…'
          }}</div>
          <div
            v-else-if="context.lightSet"
            class="module-settings__card"
            :style="{ background: module.soft }"
            data-testid="light-set"
          >
            <div class="module-settings__card-title">{{
              context.lightSet.name
            }}</div>
            <div class="module-settings__card-meta"
              >{{ context.lightSet.puzzleCount }} puzzles ·
              {{ context.lightSet.runCount }} séance{{
                context.lightSet.runCount > 1 ? 's' : ''
              }}{{
                context.lightSet.status === 'paused' ? ' · en pause' : ''
              }}</div
            >
            <router-link
              :to="`/woodpecker/${context.lightSet.id}`"
              class="module-settings__link"
              :style="{ color: module.accentInk }"
              >Voir le set →</router-link
            >
          </div>
          <template v-else>
            <div class="module-settings__empty"
              >Tu n’as pas encore de set light.</div
            >
            <router-link
              to="/woodpecker/new?mode=light"
              class="module-settings__link module-settings__link--cta"
              :style="{ background: module.deep, color: '#1b1530' }"
              data-testid="light-set-create"
              >+ Créer mon set light</router-link
            >
          </template>
        </template>
        <textarea
          v-else-if="field.type === 'text'"
          :id="`${uid}-${field.key}-input`"
          v-model="values[field.key]"
          rows="2"
          maxlength="200"
          class="module-settings__text"
          :placeholder="field.placeholder || 'Consigne, objectif…'"
        />
      </div>
    </div>

    <div
      v-if="issue && !themesKey"
      class="module-settings__issue"
      data-testid="module-issue"
      >{{ issue }}</div
    >
    <div v-if="themesKey" class="module-settings__actions">
      <button
        type="button"
        class="module-settings__save"
        data-testid="themes-done"
        @click="themesKey = null"
        >Valider les thèmes</button
      >
    </div>
    <div v-else class="module-settings__actions">
      <button
        v-if="mode === 'edit'"
        type="button"
        class="module-settings__remove"
        data-testid="module-remove"
        @click="emit('remove')"
        >Retirer</button
      >
      <button
        type="button"
        class="module-settings__save"
        data-testid="module-save"
        :disabled="!!issue"
        @click="emit('save', values)"
        >{{ mode === 'edit' ? 'Enregistrer' : 'Ajouter à la session' }} ·
        {{ formatMinutes(moduleMinutes(module, values)) }}</button
      >
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, useId, watch } from 'vue'
import ProfAvatar from '@/components/session/ProfAvatar.vue'
import ThemePicker from '@/components/session/ThemePicker.vue'
import { usePuzzleStore } from '@/stores/puzzle'
import { useSessionStore } from '@/stores/session'
import {
  MAX_THEMES,
  defaultValues,
  formatMinutes,
  itemIssue,
  moduleMinutes
} from '@/utils/session/catalog'

/**
 * A module's settings form, before adding it to the session or to edit it there. Works on a copy:
 * nothing changes until "save", which waits until the module can be played (a repertoire chosen,
 * a light set ongoing). Puzzle themes are picked in a second view of the same panel.
 */
const props = defineProps({
  /** @type {import('vue').PropType<import('@/utils/session/catalog').Module>} */
  module: { type: Object, required: true },
  /** The item's current settings when editing; the module's defaults otherwise. */
  initial: { type: Object, default: null },
  /** @type {import('vue').PropType<'add'|'edit'>} */
  mode: { type: String, default: 'add' }
})

const emit = defineEmits(['save', 'remove'])

const uid = useId()

/** @type {Record<string, any>} */
const values = reactive(
  JSON.parse(JSON.stringify(props.initial ?? defaultValues(props.module)))
)

const session = useSessionStore()
const puzzles = usePuzzleStore()
const context = computed(() => session.context)

/** The themes field being picked in the second view, if any. */
const themesKey = ref(/** @type {string|null} */ (null))

const issue = computed(() => itemIssue(props.module, values, context.value))

// A new module on a user with a single repertoire: it is the obvious choice.
watch(
  () => context.value.repertoires,
  list => {
    if (props.mode !== 'add' || list.length !== 1) return
    for (const f of props.module.fields) {
      if (f.type === 'repertoire' && !values[f.key].length) {
        values[f.key] = [list[0].id]
      }
    }
  },
  { immediate: true }
)

const kicker = computed(
  () =>
    `PROF ${props.module.prof.toUpperCase()} · ${props.mode === 'edit' ? 'MODIFIER' : 'NOUVEAU MODULE'}`
)

/**
 * @param {import('@/utils/session/catalog').Field} field
 * @returns {string} the value shown next to the label
 */
function display(field) {
  const v = values[field.key]
  if (field.type === 'slider') return field.format ? field.format(v) : String(v)
  if ((field.type === 'many' || field.type === 'repertoire') && v.length) {
    return `${v.length} choisi${v.length > 1 ? 's' : ''}`
  }
  if (field.type === 'themes')
    return v.length ? `${v.length} / ${MAX_THEMES}` : ''
  return ''
}

/**
 * Adds or removes an id in a multiple-choice setting.
 *
 * @param {string} key
 * @param {string} id
 */
function toggleId(key, id) {
  const v = values[key]
  values[key] = v.includes(id)
    ? v.filter((/** @type {string} */ x) => x !== id)
    : [...v, id]
}

/**
 * @param {import('@/utils/session/catalog').Field} field
 * @param {string} option
 */
function isOn(field, option) {
  const v = values[field.key]
  return field.type === 'one' ? v === option : v.includes(option)
}

/**
 * @param {import('@/utils/session/catalog').Field} field
 * @param {string} option
 */
function pick(field, option) {
  if (field.type === 'one') {
    values[field.key] = option
    return
  }
  const v = values[field.key]
  values[field.key] = v.includes(option)
    ? v.filter((/** @type {string} */ x) => x !== option)
    : [...v, option]
}
</script>

<style scoped lang="scss">
.module-settings {
  display: flex;
  flex-direction: column;
  min-height: 0;
  height: 100%;
}

.module-settings__hero {
  position: relative;
  overflow: hidden;
  flex: none;
  min-height: 84px;
  margin: 0 16px;
  padding: 14px 96px 14px 18px;
  border-radius: 22px;
}

.module-settings__kicker {
  position: relative;
  font-size: 11px;
  font-weight: 700;
}

.module-settings__title {
  font-size: 21px;
  line-height: 1.15;
}

.module-settings__desc {
  margin-top: 2px;
  font-size: 13px;
  line-height: 1.4;
}

.module-settings__profile {
  position: relative;
  z-index: 1;
  display: inline-flex;
  margin-top: 8px;
  padding: 6px 11px;
  border-radius: 999px;
  background: #fff;
  color: #1b1530;
  font-size: 12px;
  font-weight: 700;
  text-decoration: none;
}

.module-settings__avatar {
  position: absolute;
  right: 6px;
  bottom: -10px;
  width: 84px;
  height: 84px;
}

.module-settings__fields {
  flex: 1;
  overflow-y: auto;
  padding: 4px 20px 8px;
}

.module-settings__field {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 0;
  border-bottom: 1px solid var(--cm-subtle);
}

.module-settings__field-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 28px;
}

.module-settings__label,
.module-settings__display {
  font-size: 14px;
  font-weight: 700;
}

.module-settings__hint {
  margin-top: -6px;
  color: var(--cm-muted);
  font-size: 12.5px;
}

.module-settings__slider {
  width: 100%;
  margin: 0;
}

.module-settings__switch {
  position: relative;
  flex: none;
  width: 48px;
  height: 28px;
  border: none;
  border-radius: 999px;
  cursor: pointer;
}

.module-settings__knob {
  position: absolute;
  top: 3px;
  left: 3px;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.25);
  transition: left 0.15s ease;

  &--on {
    left: 23px;
  }
}

.module-settings__options {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.module-settings__option {
  height: 38px;
  padding: 0 14px;
  border: 1.5px solid var(--cm-line);
  border-radius: 12px;
  background: var(--cm-surface);
  color: var(--cm-ink);
  font: inherit;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;

  &[aria-pressed='true'] {
    color: #1b1530;
  }
}

.module-settings__tag {
  display: inline-flex;
  align-items: center;
  height: 38px;
  padding: 0 12px;
  border-radius: 12px;
  color: #1b1530;
  font-size: 13px;
  font-weight: 600;
}

.module-settings__piece {
  display: inline-block;
  width: 12px;
  height: 12px;
  margin-right: 6px;
  border: 1.5px solid #1b1530;
  border-radius: 50%;
  vertical-align: -1px;

  &--white {
    background: #fff;
  }

  &--black {
    background: #1b1530;
  }
}

.module-settings__empty {
  color: var(--cm-muted);
  font-size: 13.5px;
}

.module-settings__link {
  align-self: flex-start;
  font-size: 13.5px;
  font-weight: 700;
  text-decoration: none;

  &--cta {
    padding: 10px 14px;
    border-radius: 12px;
  }
}

.module-settings__card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 14px;
  border-radius: 14px;
  color: #1b1530;
}

.module-settings__card-title {
  font-size: 15px;
  font-weight: 700;
}

.module-settings__card-meta {
  font-size: 13px;
}

.module-settings__subhead {
  display: flex;
  align-items: center;
  gap: 10px;
  padding-top: 10px;
}

.module-settings__subback {
  width: 32px;
  height: 32px;
  border: none;
  border-radius: 10px;
  background: var(--cm-subtle);
  color: var(--cm-ink);
  font: inherit;
  cursor: pointer;
}

.module-settings__issue {
  flex: none;
  padding: 8px 20px 0;
  color: var(--cm-danger);
  font-size: 13px;
  font-weight: 700;
}

.module-settings__text {
  box-sizing: border-box;
  width: 100%;
  padding: 10px 12px;
  border: 1.5px solid var(--cm-line);
  border-radius: 14px;
  background: var(--cm-page);
  color: var(--cm-ink);
  font: inherit;
  font-size: 14px;
  resize: none;
  outline: none;

  &:focus {
    border-color: var(--cm-brand);
  }
}

.module-settings__actions {
  display: flex;
  flex: none;
  gap: 10px;
  padding: 12px 16px 20px;
  border-top: 1px solid var(--cm-subtle);
}

.module-settings__remove,
.module-settings__save {
  height: 52px;
  border: none;
  border-radius: 17px;
  font: inherit;
  font-weight: 700;
  cursor: pointer;
}

.module-settings__remove {
  padding: 0 16px;
  background: var(--cm-danger-soft);
  color: var(--cm-danger);
  font-size: 14px;
}

.module-settings__save {
  flex: 1;
  background: #1b1530;
  color: #fff;
  font-size: 15px;

  &:disabled {
    opacity: 0.45;
    cursor: not-allowed;
  }
}

.body--dark .module-settings__save {
  background: var(--cm-brand);
}
</style>
