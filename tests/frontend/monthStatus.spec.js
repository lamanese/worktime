/**
 * SPDX-FileCopyrightText: 2026 Ahmad Gilbeau-Hammoud
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { resolveMonthStatus, resolveCanSubmit } from '../../src/utils/monthStatus.js'

const e = (status) => ({ status })

describe('resolveMonthStatus', () => {
    it('prefers the status reported by the backend', () => {
        expect(resolveMonthStatus('submitted', [e('draft')])).toBe('submitted')
        expect(resolveMonthStatus('rejected', [])).toBe('rejected')
    })
    it('falls back to the entry-based derivation', () => {
        expect(resolveMonthStatus(null, [])).toBe('draft')
        expect(resolveMonthStatus(undefined, [e('approved'), e('approved')])).toBe('approved')
        expect(resolveMonthStatus(null, [e('submitted'), e('approved')])).toBe('submitted')
        expect(resolveMonthStatus(null, [e('submitted'), e('draft')])).toBe('draft')
        expect(resolveMonthStatus(null, [e('rejected')])).toBe('draft')
    })
})

describe('resolveCanSubmit', () => {
    it('uses the backend decision when present', () => {
        expect(resolveCanSubmit(true, 'draft', [])).toBe(true)
        expect(resolveCanSubmit(false, 'draft', [e('draft')])).toBe(false)
    })
    it('falls back to draft/rejected entries in a non-approved month', () => {
        expect(resolveCanSubmit(null, 'draft', [e('draft')])).toBe(true)
        expect(resolveCanSubmit(null, 'submitted', [e('rejected')])).toBe(true)
        expect(resolveCanSubmit(null, 'approved', [e('draft')])).toBe(false)
        expect(resolveCanSubmit(null, 'draft', [])).toBe(false)
    })
})
