import assert from 'node:assert/strict';
import { test } from 'node:test';
import { authMessage, binaryBytes, inputBytes, MAX_COLS, MAX_ROWS, resizeMessage, SUBPROTOCOL } from '../../resources/js/terminal-protocol.js';

test('the terminal offers its own subprotocol', () => {
    assert.equal(SUBPROTOCOL, 'nuvabill.terminal.v1');
});

test('a resize stays within sane sizes', () => {
    assert.deepEqual(JSON.parse(resizeMessage(80, 24)), { type: 'resize', cols: 80, rows: 24 });
    assert.deepEqual(JSON.parse(resizeMessage(100000, 99999)), { type: 'resize', cols: MAX_COLS, rows: MAX_ROWS });
    assert.deepEqual(JSON.parse(resizeMessage(-3, 'x')), { type: 'resize', cols: 1, rows: 1 });
    assert.deepEqual(JSON.parse(resizeMessage(80.7, 24.2)), { type: 'resize', cols: 80, rows: 24 });
});

test('the sign-in frame carries only the username and password', () => {
    assert.equal(authMessage({}), null);
    assert.equal(authMessage(null), null);
    assert.equal(authMessage({ password: '' }), null);
    assert.deepEqual(JSON.parse(authMessage({ username: 'root', password: 's3cret', target: 'x', extra: 1 })), { type: 'auth', username: 'root', password: 's3cret' });
    assert.deepEqual(JSON.parse(authMessage({ password: 's3cret' })), { type: 'auth', password: 's3cret' });
});

test('typed text goes out as UTF-8 bytes', () => {
    assert.deepEqual([...inputBytes('ls\r')], [108, 115, 13]);
    assert.deepEqual([...inputBytes('é')], [0xc3, 0xa9]);
    assert.deepEqual([...inputBytes(null)], []);
});

test('binary strings keep one byte per character', () => {
    assert.deepEqual([...binaryBytes('\x1b[M a')], [27, 91, 77, 32, 97]);
    assert.deepEqual([...binaryBytes('Ā')], [0]);
});
