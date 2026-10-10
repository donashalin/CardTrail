const gbp = new Intl.NumberFormat('en-GB', { style: 'currency', currency: 'GBP' });

/** 123456 → "£1,234.56"; negative amounts (credits) → "-£12.00". */
export function formatPence(pence: number | null): string {
    if (pence === null) {
        return '—';
    }

    const formatted = gbp.format(Math.abs(pence) / 100);

    return pence < 0 ? `-${formatted}` : formatted;
}

/** "2026-10-03" → "3 Oct 2026", without timezone surprises. */
export function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const [year, month, day] = iso.slice(0, 10).split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        timeZone: 'UTC',
    });
}
