import 'dotenv/config';
import assert from 'node:assert/strict';

assert.equal(process.env.E2E_ALLOW_CLEAR, 'true', 'Gunakan database uji terpisah dan set E2E_ALLOW_CLEAR=true; tes ini menghapus data transaksi.');

const base = process.env.E2E_API_URL || `http://localhost:${process.env.PORT || 3000}/api`;
const call = async (action, payload = {}, token = '') => {
  const body = new URLSearchParams({ action, ...payload });
  if (token) body.set('token', token);
  const response = await fetch(base, { method:'POST', body });
  const data = await response.json();
  assert.equal(data.ok, true, `${action}: ${data.message || response.status}`);
  return data;
};

const ping = await call('ping');
assert.match(ping.message, /MySQL/);
const login = await call('login', { username:process.env.DEV_ADMIN_USERNAME, password:process.env.DEV_ADMIN_PASSWORD });
assert.ok(login.token);
const token = login.token;
const before = await call('appdata', { includeBootstrap:'1' }, token);
for (const key of ['entries','spkEntries','apdEntries','downtimeEntries','adjustments']) {
  assert.equal(before[key].length, 0, `Database uji harus kosong: ${key}`);
}
await call('master.add',{category:'operator',value:'Operator E2E'},token);
const spk = await call('spk.batchCreate',{data:JSON.stringify([{produk:'Produk E2E Otomatis',botol:'Botol E2E Otomatis',produksiDus:10,qtyPerDus:12}])},token);
assert.equal(spk.saved[0].qty,120);
const batchNo=spk.saved[0].batchNo;
const entry = await call('entry.batchCreate',{data:JSON.stringify([{clientRequestId:'e2e-filling-1',line:'filling',tanggal:new Date().toISOString().slice(0,10),operator:'Operator E2E',produk:'Produk E2E Otomatis',botol:'Botol E2E Otomatis',batchNo,qtyKardus:5,qtyBotolPerKardus:12,qtyBotolPecah:0,qtyKardusBasah:0}])},token);
assert.equal(entry.entries[0].totalQty,60);
assert.equal(entry.remainders[0].sisaQty,60);
const data = await call('appdata',{includeBootstrap:'1'},token);
assert.ok(data.entries.some(item=>item.id==='e2e-filling-1'));
assert.ok(data.master.produk.includes('Produk E2E Otomatis'));
assert.ok(data.master.botol.includes('Botol E2E Otomatis'));
assert.equal(data.user.active, true);

// Repeated upserts, JSON settings, DATETIME and date-only round trips.
for (const value of [123456,123457]) {
  const result = await call('settings.kpiTargets.set', { fillingValue:value, pressValue:70001 }, token);
  assert.equal(result.settings.kpiFillingOutputTargetMonthly, value);
}
await call('settings.kpiTargets.set', {
  fillingValue:before.settings.kpiFillingOutputTargetMonthly,
  pressValue:before.settings.kpiPressOutputTargetMonthly,
}, token);
const tanggal = new Date().toISOString().slice(0,10);
for (const minute of ['30','45']) {
  const arrivalTimestamp = `${tanggal}T01:${minute}:00.000Z`;
  const downtime = await call('downtime.upsert', { data:JSON.stringify({ productionStartTime:'08:00', arrivalTimestamp, alasan:'E2E' }) }, token);
  assert.equal(downtime.entry.timestamp, arrivalTimestamp);
  assert.equal(downtime.entry.tanggal, tanggal);
}

// JSON scores, binary photo round trip, IN arrays (including empty), and cascades.
const dataUrl = 'data:image/jpeg;base64,/9j/2Q==';
const photo = await call('apd.photo.upload', { dataUrl }, token);
const apdPayload = { clientRequestId:'e2e-apd-1', tanggal, operator:'Operator E2E', scores:{ masker:2 }, percentage:80, photoFileIds:[photo.photoFileId] };
for (let i=0;i<2;i++) {
  const apd = await call('apd.batchCreate', { data:JSON.stringify([apdPayload]) }, token);
  assert.deepEqual(apd.entries[0].scores, { masker:2 });
  assert.deepEqual(apd.entries[0].photoFileIds, [photo.photoFileId]);
}
await assert.rejects(call('apd.batchCreate', { data:JSON.stringify([{ ...apdPayload, clientRequestId:'e2e-apd-conflict' }]) }, token), /sudah ada/);
assert.equal((await call('apd.photo.preview', { photoFileId:photo.photoFileId }, token)).dataUrl, dataUrl);
await call('apd.update', { id:'e2e-apd-1', data:JSON.stringify({ ...apdPayload, photoFileIds:[] }) }, token);

// MySQL assignment order must compare botol_pecah_jenis to the OLD bottle.
await call('master.add', { category:'produk', value:'Produk E2E Baru' }, token);
await call('master.add', { category:'botol', value:'Botol E2E Baru' }, token);
await call('spk.update', { batchNo, data:JSON.stringify({ produk:'Produk E2E Baru', botol:'Botol E2E Baru', produksiDus:10, qtyPerDus:12 }) }, token);
const updated = await call('appdata', {}, token);
assert.equal(updated.entries[0].botolPecahJenis, 'Botol E2E Baru');
assert.equal(updated.entries[0].produk, 'Produk E2E Baru');
assert.equal(updated.entries[0].totalQty, 60);
assert.equal(updated.entries[0].updateCount, 1);
await call('spk.batchDelete', { batchNos:'[]' }, token);

await call('maintenance.inputData.clear',{confirmation:'HAPUS SEMUA DATA'},token);
const cleared = await call('appdata', {}, token);
for (const key of ['entries','spkEntries','apdEntries','downtimeEntries','adjustments']) assert.equal(cleared[key].length, 0);
for (const [category,value] of [['operator','Operator E2E'],['produk','Produk E2E Otomatis'],['botol','Botol E2E Otomatis'],['produk','Produk E2E Baru'],['botol','Botol E2E Baru']]) {
  await call('master.remove', { category,value }, token);
}
await call('logout',{},token);
console.log('E2E API MySQL lulus: login, master, SPK, entry, saldo, JSON, waktu, foto APD, cleanup, logout.');
