const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
(async () => {
  const handlers = {}, storage = new Map();
  let poll, response, tones = 0;
  class AudioContext {
    state = 'running'; currentTime = 0;
    createOscillator() { return { frequency: {}, connect() {}, start() { tones++; }, stop() {} }; }
    createGain() { return { gain: { setValueAtTime() {}, exponentialRampToValueAtTime() {} }, connect() {} }; }
  }
  const document = { hidden: false, documentElement: { dataset: {} } };
  const window = { AudioContext, addEventListener(name, fn) { handlers[name] = fn; }, setInterval(fn) { poll = fn; } };
  const notification = (id, allowed = 1, read = 0) => ({ id, type: 'message', sound_allowed: allowed, is_read: read });
  response = { success: true, user_id: 1, notifications: [notification(1)] };
  vm.runInNewContext(readFileSync('assets/js/message_notifications.js', 'utf8'), {
    window, document, localStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value) },
    fetch: async () => ({ json: async () => response }), console, Date
  });
  await new Promise(resolve => setImmediate(resolve));
  await handlers.pointerdown();
  assert.equal(tones, 0, 'Initial backlog must stay silent');
  response.notifications = [notification(2)]; await poll(); assert.equal(tones, 2, 'New message plays two tones');
  await poll(); assert.equal(tones, 2, 'Same notification must not replay');
  response.notifications = [notification(3, 0)]; await poll(); assert.equal(tones, 2, 'Muted history must stay silent');
  document.documentElement.dataset.presenceStatus = 'away';
  response.notifications = [notification(4)]; await poll(); await handlers.keydown(); assert.equal(tones, 2, 'DND does not queue sounds');
  document.documentElement.dataset.presenceStatus = 'online';
  await poll(); assert.equal(tones, 2, 'Leaving DND does not replay old messages');
  response.notifications = [notification(5, 1, 1)]; await poll(); assert.equal(tones, 2, 'Read messages stay silent');
  console.log('Notification sound: backlog, new message, duplicates, mute, DND, delayed sound and read checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
