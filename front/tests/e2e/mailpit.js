import { expect } from '@playwright/test'
import { MAILPIT_HTTP_PORT } from './ports.js'

/**
 * The emails of the e2e API, read through Mailpit's REST API (https://mailpit.axllent.org/docs/api-v1/).
 * Mailpit is started by Playwright (playwright.config.js) with an in-memory store.
 */
const API = `http://localhost:${MAILPIT_HTTP_PORT}/api/v1`

/**
 * The plain-text body of the latest email sent to `to`, waiting for it to arrive.
 *
 * @param {string} to
 * @param {{subject?: RegExp, timeout?: number}} [options] only an email whose subject matches
 * @returns {Promise<string>}
 */
export async function emailTo(to, { subject, timeout = 15_000 } = {}) {
  let id = null
  await expect
    .poll(
      async () => {
        const response = await fetch(
          `${API}/search?query=${encodeURIComponent(`to:"${to}"`)}`
        )
        const { messages = [] } = await response.json()
        const match = messages.find(m => !subject || subject.test(m.Subject))
        id = match?.ID ?? null
        return id
      },
      { timeout, message: `an email to ${to}` }
    )
    .not.toBeNull()

  const message = await (await fetch(`${API}/message/${id}`)).json()
  return message.Text
}

/**
 * The first link of an email body that starts with `prefix`.
 *
 * @param {string} text
 * @param {string} prefix
 * @returns {string}
 */
export function linkIn(text, prefix) {
  const link = text.split(/\s+/).find(word => word.startsWith(prefix))
  expect(link, `a link starting with ${prefix}`).toBeTruthy()
  return link
}

/**
 * The 6-digit code of an email body (sign-in code).
 *
 * @param {string} text
 * @returns {string}
 */
export function codeIn(text) {
  const match = text.match(/\b(\d{6})\b/)
  expect(match, 'a 6-digit code').toBeTruthy()
  return match[1]
}
