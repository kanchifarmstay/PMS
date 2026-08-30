'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const dates = require('../date-utils.js');

test('date-only arithmetic is stable in Asia/Kolkata and across month boundaries', () => {
    assert.equal(process.env.TZ, 'Asia/Kolkata');
    assert.equal(dates.addDays('2030-01-31', 1), '2030-02-01');
    assert.equal(dates.addDays('2032-02-28', 1), '2032-02-29');
    assert.equal(dates.daysBetween('2030-03-10', '2030-03-12'), 2);
    assert.equal(dates.todayLocal(new Date(2030, 0, 2, 0, 15)), '2030-01-02');
    assert.throws(() => dates.addDays('2030-02-30', 1));
});

test('exclusive checkout ranges expand and overlap correctly', () => {
    assert.deepEqual([...dates.buildBlockedSet([['2030-04-01', '2030-04-03']])], ['2030-04-01', '2030-04-02']);
    assert.equal(dates.isRangeBlocked('2030-04-03', '2030-04-04', [['2030-04-01', '2030-04-03']]), false);
    assert.equal(dates.isRangeBlocked('2030-04-02', '2030-04-04', [['2030-04-01', '2030-04-03']]), true);
    assert.equal(dates.nextAvailableDate('2030-04-01', new Set(['2030-04-01', '2030-04-02'])), '2030-04-03');
});
