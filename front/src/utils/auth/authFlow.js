import { t } from '@/i18n'

/**
 * The sign-in and early access screens (designs "Connexion" and "Accès anticipé", docs/AUTH.md and
 * docs/EARLY_ACCESS.md): the pure parts, so that they can be tested alone. The server stays the
 * judge of everything checked here (address, key, password strength).
 */

/** A light check before asking the server (which validates the address itself). */
const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

/** An invitation key: 32 letters and digits (docs/EARLY_ACCESS.md). */
export const KEY_LENGTH = 32
const KEY_PATTERN = /^[A-Za-z0-9]{32}$/

/** The API's minimum password length (docs/SECURITY.md § 2.1). */
export const PASSWORD_MIN_LENGTH = 12

/** What the API's invitation refusals mean (docs/EARLY_ACCESS.md). */
export const KEY_ERRORS = {
  get invitation_required() {
    return t('auth.key.required')
  },
  get invitation_invalid() {
    return t('auth.key.invalid')
  },
  get invitation_expired() {
    return t('auth.key.expired')
  }
}

/** Sign-up refusals carried back by an OAuth callback: they belong to the early access screen. */
export const INVITATION_REASONS = Object.keys(KEY_ERRORS)

/** Sign-in providers, the recommended one first (design "Connexion"). */
export const PROVIDERS = [
  { id: 'lichess', label: 'Lichess', glyph: '♞︎' },
  { id: 'google', label: 'Google', glyph: 'G' }
]

/**
 * @param {unknown} id
 * @returns {{id: string, label: string, glyph: string}|null}
 */
export function providerById(id) {
  return PROVIDERS.find(p => p.id === id) ?? null
}

/**
 * @param {string} value
 * @returns {boolean}
 */
export function isEmail(value) {
  return EMAIL_PATTERN.test(value.trim())
}

/**
 * The key as typed or pasted, without spaces or line breaks (an email client may wrap it).
 *
 * @param {string} value
 * @returns {string}
 */
export function normalizeKey(value) {
  return value.replace(/\s+/g, '')
}

/**
 * @param {string} key a normalized key
 * @returns {string} '' when well formed, else why not
 */
export function keyFormatError(key) {
  if (key === '') return KEY_ERRORS.invitation_required
  return KEY_PATTERN.test(key)
    ? ''
    : t('auth.key.format', { length: KEY_LENGTH })
}

const STRENGTH_LABELS = {
  get 1() {
    return t('auth.strength.weak')
  },
  get 2() {
    return t('auth.strength.fair')
  },
  get 3() {
    return t('auth.strength.good')
  },
  get 4() {
    return t('auth.strength.strong')
  }
}

/** Entropy bits the API asks for: Symfony's PasswordStrength, STRENGTH_MEDIUM and above. */
const STRENGTH_THRESHOLDS = [80, 100, 120]

/**
 * The entropy Symfony's PasswordStrengthValidator::estimateStrength() gives a password, computed
 * the same way on its UTF-8 bytes: the distinct bytes times log2 of the character pool they draw
 * from, plus the repeated bytes times log2 of the distinct ones.
 *
 * @param {string} password
 * @returns {number}
 */
export function passwordEntropy(password) {
  const bytes = new TextEncoder().encode(password)
  if (bytes.length === 0) return 0
  const distinct = new Set(bytes)
  let control = 0
  let digit = 0
  let upper = 0
  let lower = 0
  let symbol = 0
  let other = 0
  for (const byte of distinct) {
    if (byte < 32 || byte === 127) control = 33
    else if (byte >= 48 && byte <= 57) digit = 10
    else if (byte >= 65 && byte <= 90) upper = 26
    else if (byte >= 97 && byte <= 122) lower = 26
    else if (byte >= 128) other = 128
    else symbol = 33
  }
  const pool = lower + upper + digit + symbol + control + other

  return (
    distinct.size * Math.log2(pool) +
    (bytes.length - distinct.size) * Math.log2(distinct.size)
  )
}

/**
 * Would the API take this new password? Its length and strength only: known leaks are checked by
 * the API alone.
 *
 * @param {string} password
 * @returns {boolean}
 */
export function isAcceptablePassword(password) {
  return (
    [...password].length >= PASSWORD_MIN_LENGTH &&
    passwordEntropy(password) >= STRENGTH_THRESHOLDS[0]
  )
}

/**
 * The strength meter of the new password fields (4 bars), on the API's own scale: one bar
 * ("Trop faible") for every password it refuses, then medium, strong and very strong.
 *
 * @param {string} password
 * @returns {{score: 0|1|2|3|4, label: string}}
 */
export function passwordStrength(password) {
  if (!password) return { score: 0, label: '' }
  const entropy = passwordEntropy(password)
  const score = isAcceptablePassword(password)
    ? 1 + STRENGTH_THRESHOLDS.filter(bits => entropy >= bits).length
    : 1

  return {
    score: /** @type {0|1|2|3|4} */ (score),
    label: STRENGTH_LABELS[score]
  }
}

/**
 * A title split into letters that drop in one after the other (design "Accès anticipé").
 *
 * @param {string} text
 * @param {number} [offset] letters already shown before this text (for the delays)
 * @returns {{ch: string, delay: string, tilt: string}[]}
 */
export function dropLetters(text, offset = 0) {
  return [...text].map((ch, i) => ({
    ch,
    delay: `${(i + offset) * 0.04}s`,
    tilt: `${(i + offset) % 2 ? 14 : -14}deg`
  }))
}

/** Set before leaving for a provider to sign up: the callback then opens the welcome screen. */
const WELCOME_KEY = 'dontstayrooky.welcome'
/** Set before linking Lichess from a page that wants the user back (welcome screen, repertoire from my games). */
const LINK_RETURN_KEY = 'dontstayrooky.linkReturn'
/** Set by a landing call to action: where a provider sign-in (or sign-up) leads. */
const AFTER_SIGN_IN_KEY = 'dontstayrooky.afterSignIn'

export const WELCOME_PATH = '/bienvenue'
export const AUTO_REPERTOIRE_PATH = '/repertoire/auto'
/** The tailored session (docs/COACH.md). */
export const COACH_PATH = '/session/auto'
/** The guided tour (docs/ONBOARDING.md). */
export const TOUR_PATH = '/visite'
/** The only pages a link may come back to (never another site). */
const LINK_RETURNS = [WELCOME_PATH, AUTO_REPERTOIRE_PATH, COACH_PATH, TOUR_PATH]
/** The only pages a provider sign-in may lead to, past the dashboard (never another site). */
const AFTER_SIGN_IN = [AUTO_REPERTOIRE_PATH, COACH_PATH]

/**
 * @param {string} key
 * @param {string} value
 */
function remember(key, value) {
  try {
    sessionStorage.setItem(key, value)
  } catch {
    // Storage unavailable: the user lands on the dashboard instead.
  }
}

/**
 * @param {string} key
 * @returns {string|null} the value, forgotten
 */
function takeBack(key) {
  try {
    const value = sessionStorage.getItem(key)
    sessionStorage.removeItem(key)
    return value
  } catch {
    return null
  }
}

/** A sign-up with a provider is starting in this tab. */
export function markWelcome() {
  remember(WELCOME_KEY, '1')
}

/**
 * @returns {boolean} whether this tab started a sign-up (forgotten once read)
 */
export function takeWelcome() {
  return takeBack(WELCOME_KEY) === '1'
}

/** Linking Lichess from the welcome screen. */
export function markLinkFromWelcome() {
  markLinkReturn(WELCOME_PATH)
}

/**
 * @returns {boolean} whether the link was started from the welcome screen (forgotten once read)
 */
export function takeLinkFromWelcome() {
  return takeLinkReturn() === WELCOME_PATH
}

/**
 * Linking Lichess from a page the callback comes back to.
 *
 * @param {string} path one of the allowed pages
 */
export function markLinkReturn(path) {
  if (LINK_RETURNS.includes(path)) remember(LINK_RETURN_KEY, path)
}

/**
 * @returns {string|null} the page that started the link, if allowed (forgotten once read)
 */
export function takeLinkReturn() {
  const path = takeBack(LINK_RETURN_KEY)
  return path !== null && LINK_RETURNS.includes(path) ? path : null
}

/**
 * A provider sign-in started from a landing call to action: the callback leads to this page, also
 * after the early access screen's second round trip (same tab).
 *
 * @param {string} path one of the allowed pages
 */
export function markAfterSignIn(path) {
  if (AFTER_SIGN_IN.includes(path)) remember(AFTER_SIGN_IN_KEY, path)
}

/**
 * @returns {string|null} the page a provider sign-in leads to, if allowed (forgotten once read)
 */
export function takeAfterSignIn() {
  const path = takeBack(AFTER_SIGN_IN_KEY)
  return path !== null && AFTER_SIGN_IN.includes(path) ? path : null
}
