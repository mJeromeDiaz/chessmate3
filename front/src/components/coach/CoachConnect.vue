<template>
  <div class="cs-screen cs-up">
    <div class="cs-cols">
      <div class="cs-cols__side">
        <div class="cs-albert">
          <img
            :src="profImage('albert-v2-pointer')"
            alt="Albert"
            class="cs-albert__img cs-bob"
          />
          <div class="cs-bubble">
            {{ $t('coach.connect.bubble', { n: COACH_GAMES }) }}
          </div>
        </div>
        <h1 class="cs-title">{{ $t('coach.connect.title') }}</h1>
        <div class="cs-actions">
          <button
            type="button"
            class="cs-cta cs-cta--night cs-cta--big"
            :disabled="!identity || busy"
            data-testid="coach-start"
            @click="emit('start')"
            >{{ $t('coach.connect.start', { n: COACH_GAMES }) }}</button
          >
        </div>
        <div v-if="error" class="cs-error" role="alert">{{ error }}</div>
      </div>

      <div class="cs-cols__main">
        <div class="cs-card">
          <div class="cs-kicker">{{ $t('coach.connect.account') }}</div>
          <div
            v-if="identity"
            class="cs-account cs-fade"
            data-testid="coach-account"
          >
            <div class="cs-account__tile">♞︎</div>
            <div class="cs-account__text">
              <span class="cs-account__name">@{{ identity.username }}</span>
              <span v-if="ratings" class="cs-account__sub">{{ ratings }}</span>
            </div>
            <span class="cs-account__on"
              ><span class="cs-account__dot" />{{
                $t('repertoireAuto.connect.connected')
              }}</span
            >
          </div>
          <template v-else>
            <div class="cs-text">
              {{ $t('coach.connect.noGames') }}
            </div>
            <a
              v-if="!signedIn"
              :href="authApi.oauthLoginUrl('lichess')"
              class="cs-cta cs-cta--night"
              data-testid="coach-sign-in"
              @click="markAfterSignIn(COACH_PATH)"
              ><span class="cs-glyph">♞︎</span
              >{{ $t('repertoireAuto.connect.link') }}</a
            >
            <button
              v-else
              type="button"
              class="cs-cta cs-cta--night"
              :disabled="linking"
              data-testid="coach-link"
              @click="emit('link')"
            >
              <span class="cs-glyph">♞︎</span
              >{{
                linking
                  ? $t('repertoireAuto.connect.linking')
                  : $t('repertoireAuto.connect.link')
              }}
            </button>
            <div v-if="!signedIn" class="cs-text cs-text--small">
              {{ $t('coach.connect.noAccount') }}
            </div>
          </template>
        </div>

        <label
          v-if="offerRepertoire"
          class="cs-card cs-option"
          :class="{ 'cs-card--off': !identity }"
          data-testid="coach-repertoire-option"
        >
          <input
            type="checkbox"
            class="cs-option__box"
            :checked="withRepertoire"
            @change="
              emit(
                'update:withRepertoire',
                /** @type {HTMLInputElement} */ ($event.target).checked
              )
            "
          />
          <span class="cs-option__text">
            <b>{{ $t('coach.connect.repertoire') }}</b>
            <span class="cs-text cs-text--small">{{
              $t('coach.connect.repertoireHint')
            }}</span>
          </span>
        </label>

        <div class="cs-card">
          <div class="cs-kicker">{{ $t('coach.connect.watch') }}</div>
          <ul class="cs-list">
            <li>{{ $t('coach.connect.watchOpenings') }}</li>
            <li>{{ $t('coach.connect.watchEndgames') }}</li>
            <li>{{ $t('coach.connect.watchGames', { n: COACH_GAMES }) }}</li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Step 1 of the tailored session (design "Session perso"): the Lichess account, required. A
 * visitor signs in (or up) with Lichess and comes back here; a user without Lichess links it.
 * The repertoire can be generated from the same games when the user has none.
 */
import { computed } from 'vue'
import { authApi } from '@/services/api'
import { COACH_PATH, markAfterSignIn } from '@/utils/auth/authFlow'
import { COACH_GAMES } from '@/utils/coach'
import { profImage } from '@/utils/prof/images'
import { t } from '@/i18n'

const props = defineProps({
  /** @type {import('vue').PropType<any>} the linked Lichess identity, if any */
  identity: { type: Object, default: null },
  signedIn: { type: Boolean, default: false },
  busy: { type: Boolean, default: false },
  linking: { type: Boolean, default: false },
  error: { type: String, default: '' },
  /** The user has no repertoire yet (unknown for a visitor: offered). */
  offerRepertoire: { type: Boolean, default: true },
  withRepertoire: { type: Boolean, default: true }
})
const emit = defineEmits(['start', 'link', 'update:withRepertoire'])

/** Speeds shown with their rating (labels: repertoireAuto.speeds). */
const SPEEDS = ['blitz', 'rapid', 'classical']

/** "Blitz 1842 · Rapide 1910", from the ratings the profile knows. */
const ratings = computed(() =>
  Object.entries(props.identity?.ratings ?? {})
    .filter(([perf]) => SPEEDS.includes(perf))
    .map(([perf, r]) =>
      t('repertoireAuto.connect.rating', {
        speed: t(`repertoireAuto.speeds.${perf}`),
        rating: `${/** @type {any} */ (r).rating}${/** @type {any} */ (r).provisional ? '?' : ''}`
      })
    )
    .join(' · ')
)
</script>

<style scoped lang="scss">
.cs-account {
  display: flex;
  align-items: center;
  gap: 12px;

  &__tile {
    width: 48px;
    height: 48px;
    flex: none;
    border-radius: 16px;
    background: var(--cs-night);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Segoe UI Symbol', 'DejaVu Sans', serif;
    font-size: 28px;
  }

  &__text {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  &__name {
    font-weight: 800;
    font-size: 16px;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &__sub {
    font-size: 13px;
    color: var(--cs-muted);
  }

  &__on {
    height: 28px;
    padding: 0 10px;
    border-radius: 999px;
    background: var(--cs-accent);
    color: var(--cs-night);
    font-size: 12px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 6px;
    flex: none;
  }

  &__dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--cs-night);
  }
}

.cs-option {
  flex-direction: row;
  align-items: flex-start;
  cursor: pointer;

  &__box {
    width: 22px;
    height: 22px;
    flex: none;
    margin: 2px 0 0;
    accent-color: var(--cs-brand);
  }

  &__text {
    display: flex;
    flex-direction: column;
    gap: 4px;
    font-size: 15px;
  }
}

.cs-list {
  margin: 0;
  padding-left: 18px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 14px;
  line-height: 1.45;
  color: var(--cs-ink-soft);
}
</style>
