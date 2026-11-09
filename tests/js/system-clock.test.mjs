import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { test } from 'node:test';
import assert from 'node:assert/strict';

// Execute the actual Alpine component, with a deterministic server instant and timer.
const blade = readFileSync(new URL('../../resources/views/filament/system-clock.blade.php', import.meta.url), 'utf8');
const source = blade.match(/x-data="([\s\S]*?)"/)[1]
    .replace('{{ $serverTime->getTimestampMs() }}', String(Date.parse('2026-11-07T18:59:58Z')));

test('server clock crosses midnight in Tashkent, catches up delayed ticks and cleans up', () => {
    let elapsed = 0;
    let callback;
    let stopped = false;
    const clock = runInNewContext(`(${source})`, {
        performance: { now: () => elapsed },
        setInterval: (fn, delay) => { assert.equal(delay, 1000); callback = fn; return 42; },
        clearInterval: (id) => { assert.equal(id, 42); stopped = true; },
    });
    clock.init();
    assert.equal(clock.date, '07.11.2026');
    assert.equal(clock.time, '23:59:58');
    elapsed = 1000;
    callback();
    assert.equal(clock.time, '23:59:59');
    elapsed = 65000;
    callback();
    assert.equal(clock.date, '08.11.2026');
    assert.equal(clock.time, '00:01:03');
    clock.destroy();
    assert.ok(stopped);
});
