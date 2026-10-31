import { describe, expect, it } from 'vitest'
import en from '@/i18n/en/legal.json'
import fr from '@/i18n/fr/legal.json'
import { LEGAL_SECTIONS, PUBLISHER, legalSections } from '@/utils/legal'

describe('legal pages', () => {
  it('declares every section and paragraph the texts have, in both languages', () => {
    for (const [page, sections] of Object.entries(LEGAL_SECTIONS)) {
      for (const texts of [fr, en]) {
        const keys = Object.keys(texts[page]).filter(k => k !== 'title')
        expect(keys).toEqual(sections.map(([key]) => key))
        for (const [key, count] of sections) {
          expect(texts[page][key].p, `${page}.${key}`).toHaveLength(count)
        }
      }
    }
  })

  it('names the publisher and its contact', () => {
    const controller = legalSections('privacy')[0]
    expect(controller.paragraphs[0]).toContain(PUBLISHER.name)
    expect(controller.paragraphs[0]).toContain(PUBLISHER.email)
    expect(legalSections('terms').at(-1)?.paragraphs[1]).toContain(
      PUBLISHER.email
    )
  })
})
