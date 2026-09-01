(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    else root.KfsDateUtils = api;
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function parse(date) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) throw new TypeError('Invalid date');
        const [year, month, day] = date.split('-').map(Number);
        const value = new Date(Date.UTC(year, month - 1, day));
        if (value.getUTCFullYear() !== year || value.getUTCMonth() !== month - 1 || value.getUTCDate() !== day) {
            throw new TypeError('Invalid date');
        }
        return value;
    }

    function format(date) {
        return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}-${String(date.getUTCDate()).padStart(2, '0')}`;
    }

    function addDays(date, amount) {
        const value = parse(date);
        value.setUTCDate(value.getUTCDate() + amount);
        return format(value);
    }

    function daysBetween(start, end) {
        return Math.round((parse(end).getTime() - parse(start).getTime()) / 86400000);
    }

    function buildBlockedSet(ranges) {
        const set = new Set();
        for (const [checkIn, checkOut] of ranges) {
            for (let cursor = checkIn; cursor < checkOut; cursor = addDays(cursor, 1)) set.add(cursor);
        }
        return set;
    }

    function isRangeBlocked(checkIn, checkOut, ranges) {
        return ranges.some(([start, end]) => start < checkOut && end > checkIn);
    }

    function nextAvailableDate(fromDate, blocked, limit = 365) {
        let cursor = fromDate;
        for (let i = 0; i < limit; i += 1) {
            if (!blocked.has(cursor)) return cursor;
            cursor = addDays(cursor, 1);
        }
        throw new RangeError('No available date found');
    }

    function todayLocal(now = new Date()) {
        return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
    }

    return { parse, format, addDays, daysBetween, buildBlockedSet, isRangeBlocked, nextAvailableDate, todayLocal };
}));
