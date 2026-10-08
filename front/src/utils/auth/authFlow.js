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
  invitation_required: 'Saisis ta clé d’invitation.',
  invitation_invalid:
    'Cette clé n’est pas valable : elle a peut-être déjà servi ou été remplacée.',
  invitation_expired:
    "Cette clé a expiré. Demande-en une nouvelle à l’équipe Don't Stay Rooky."
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
    : `Une clé compte ${KEY_LENGTH} lettres et chiffres.`
}

const STRENGTH_LABELS = ['', 'Faible', 'Moyen', 'Bon', 'Solide']

/**
 * A rough strength meter for the sign-up form (4 bars). Below the minimum length it never shows
 * more than "Faible": the API refuses it anyway (and also checks strength and known leaks).
 *
 * @param {string} password
 * @returns {{score: 0|1|2|3|4, label: string}}
 */
export function passwordStrength(password) {
  if (!password) return { score: 0, label: '' }
  if (password.length < PASSWORD_MIN_LENGTH) {
    return { score: 1, label: STRENGTH_LABELS[1] }
  }
  const kinds = [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter(re =>
    re.test(password)
  ).length
  const score = Math.min(
    4,
    1 +
      (password.length >= 16 ? 1 : 0) +
      (kinds >= 2 ? 1 : 0) +
      (kinds >= 3 ? 1 : 0)
  )

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
/** Set before linking Lichess from the welcome screen: the callback comes back to it. */
const LINK_RETURN_KEY = 'dontstayrooky.linkReturn'

export const WELCOME_PATH = '/bienvenue'

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
  remember(LINK_RETURN_KEY, WELCOME_PATH)
}

/**
 * @returns {boolean} whether the link was started from the welcome screen (forgotten once read)
 */
export function takeLinkFromWelcome() {
  return takeBack(LINK_RETURN_KEY) === WELCOME_PATH
}
