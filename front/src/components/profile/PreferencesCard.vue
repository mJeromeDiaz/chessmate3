<template>
  <section class="cm-card profile-card" data-testid="profile-preferences">
    <h2 class="cm-card__title">Préférences</h2>

    <div class="profile-block">
      <div class="profile-row__title">
        Échiquier
        <span class="profile-boards__current">{{ current.label }}</span>
      </div>
      <div class="profile-boards">
        <button
          v-for="board in boards"
          :key="board.value"
          type="button"
          class="profile-boards__option"
          :class="{
            'profile-boards__option--on': board.value === current.value
          }"
          :aria-pressed="board.value === current.value"
          :data-testid="`board-theme-${board.value}`"
          @click="choose({ boardTheme: board.value })"
        >
          <span class="profile-boards__preview">
            <span
              v-for="(color, i) in board.squares"
              :key="i"
              :style="{ background: color }"
            />
          </span>
          <span class="profile-boards__name">{{ board.label }}</span>
        </button>
      </div>
    </div>

    <div class="profile-row">
      <div class="profile-row__text">
        <div class="profile-row__title">Sons</div>
        <div class="profile-row__sub"
          >Coups, fin de puzzle, de module et de session</div
        >
      </div>
      <q-toggle
        :model-value="profile.moveSound"
        color="primary"
        aria-label="Sons"
        data-testid="move-sound-toggle"
        @update:model-value="choose({ moveSound: $event })"
      />
    </div>

    <ThemeSection />

    <div class="profile-block">
      <div class="profile-row__title">
        Langue <span class="profile-soon">Bientôt</span>
      </div>
      <div class="profile-lang" data-testid="profile-language">
        <button
          v-for="lang in LANGUAGES"
          :key="lang.code"
          type="button"
          class="profile-lang__option"
          :class="{ 'profile-lang__option--on': lang.code === 'fr' }"
          disabled
          >{{ lang.label }}</button
        >
      </div>
    </div>

    <div class="profile-row">
      <div class="profile-row__text">
        <div class="profile-row__title">{{
          profile.publicProfile ? 'Profil public' : 'Profil privé'
        }}</div>
        <div class="profile-row__sub">{{
          profile.publicProfile
            ? 'Les autres joueurs pourront voir ton niveau et tes stats.'
            : 'Ton niveau et tes stats ne sont visibles que par toi.'
        }}</div>
      </div>
      <q-toggle
        :model-value="profile.publicProfile"
        color="primary"
        aria-label="Profil public"
        data-testid="public-profile-toggle"
        @update:model-value="choose({ publicProfile: $event })"
      />
    </div>

    <TimezoneSection :profile="profile" />

    <q-banner v-if="error" class="profile-error" rounded>{{ error }}</q-banner>
  </section>
</template>

<script setup>
/**
 * Profile: the "Préférences" card. Board colours, move sounds and the public flag are saved at
 * once (the boards follow the profile); the public flag is stored only, no page shows a profile
 * to other players yet. Language is shown disabled ("Bientôt"): the app is French only.
 */
import { computed, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { apiErrorMessage } from '@/utils/apiError'
import {
  BOARD_THEMES,
  boardTheme,
  previewSquares
} from '@/utils/chess/boardThemes'
import ThemeSection from '@/components/profile/ThemeSection.vue'
import TimezoneSection from '@/components/profile/TimezoneSection.vue'

const props = defineProps({
  /** The /api/profile payload. */
  profile: { type: Object, required: true }
})

const LANGUAGES = [
  { code: 'fr', label: 'Français' },
  { code: 'en', label: 'English' },
  { code: 'es', label: 'Español' },
  { code: 'de', label: 'Deutsch' }
]

const auth = useAuthStore()
const error = ref('')

const boards = BOARD_THEMES.map(t => ({ ...t, squares: previewSquares(t) }))
const current = computed(() => boardTheme(props.profile.boardTheme))

/** @param {Partial<{boardTheme: string, moveSound: boolean, publicProfile: boolean}>} changes */
async function choose(changes) {
  error.value = ''
  try {
    await auth.setPreferences(changes)
  } catch (e) {
    error.value = apiErrorMessage(e)
  }
}
</script>

<style scoped lang="scss">
.profile-boards__current {
  margin-left: auto;
  font-weight: 400;
  font-size: 13px;
  color: var(--cm-muted);
}

.profile-boards {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 8px;

  @media (min-width: $breakpoint-md-min) {
    gap: 12px;
  }
}

.profile-boards__option {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 5px;
  padding: 0;
  border: none;
  background: none;
  color: var(--cm-ink);
  font: inherit;
  cursor: pointer;

  &--on .profile-boards__preview {
    box-shadow:
      0 0 0 3px var(--cm-surface),
      0 0 0 5px var(--cm-brand);
  }

  &--on .profile-boards__name {
    font-weight: 700;
  }

  &:focus-visible {
    outline: 2px solid var(--cm-brand);
    outline-offset: 4px;
    border-radius: 11px;
  }
}

.profile-boards__preview {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  width: 100%;
  aspect-ratio: 1;
  overflow: hidden;
  border-radius: 11px;

  @media (min-width: $breakpoint-md-min) {
    border-radius: 13px;
  }
}

.profile-boards__name {
  font-weight: 500;
  font-size: 11.5px;

  @media (min-width: $breakpoint-md-min) {
    font-size: 12.5px;
  }
}

.profile-lang {
  display: flex;
  gap: 4px;
  padding: 4px;
  border-radius: 14px;
  background: var(--cm-subtle);
}

.profile-lang__option {
  flex: 1;
  min-width: 0;
  height: 34px;
  border: none;
  border-radius: 11px;
  background: transparent;
  color: var(--cm-ink);
  font: inherit;
  font-weight: 700;
  font-size: 13px;
  opacity: 0.55;
  cursor: not-allowed;

  &--on {
    background: var(--cm-ink);
    color: var(--cm-surface);
    opacity: 1;
  }
}
</style>
