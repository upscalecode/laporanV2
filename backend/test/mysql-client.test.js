import test from 'node:test';
import assert from 'node:assert/strict';
import { query, runTransaction } from '../src/mysql-client.js';
import { publicUser, iso, dateText } from '../src/domain.js';

test('bindings preserve repeated parameters, empty IN lists, binary photos and UTC dates', async () => {
  const image = Buffer.from([0, 255, 32, 39]);
  const timestamp = new Date('2026-10-04T17:30:00.123Z');
  const text = "O'Brien :p2 ? \\ foto";
  const sql = 'UPDATE photos SET data=:p2,created_at=:p3 WHERE id IN (:p4) AND owner=:p1 OR author=:p1';
  const connection = { query: async (actualSql, values) => {
    assert.equal(actualSql, sql);
    assert.deepEqual(values, { p1:text, p2:image, p3:timestamp, p4:[null] });
    return [{ affectedRows:0 }];
  } };
  assert.equal((await query(connection, sql, [text,image,timestamp,[]])).rowCount, 0);
});

test('JSON and booleans have the same API representation on MySQL and MariaDB', async () => {
  const permissions = { levels:{ filling:'none' } };
  const timestamp = new Date('2026-10-04T17:30:00.123Z');
  for (const json of [JSON.stringify(permissions), permissions]) {
    const result = await query({ query: async () => [[{
      username:'tester', role:'user', active:0, archived:1, permissions:json,
      scores:'{"masker":2}', created_at:timestamp, tanggal:'2026-10-05',
    }]] }, 'SELECT * FROM users');
    assert.equal(result.rowCount, 1);
    assert.equal(publicUser(result.rows[0]).active, false);
    assert.equal(publicUser(result.rows[0]).permissions.levels.filling, 'none');
    assert.equal(result.rows[0].archived, true);
    assert.deepEqual(result.rows[0].scores, { masker:2 });
    assert.equal(iso(result.rows[0].created_at), '2026-10-04T17:30:00.123Z');
    assert.equal(dateText(result.rows[0].tanggal), '2026-10-05');
  }
});

test('SQL parameters reject arbitrary objects before mysql2 can expand them', async () => {
  const connection = { query: () => assert.fail('must not send invalid bindings') };
  await assert.rejects(query(connection, 'SELECT :p1', [{ username:'admin' }]), TypeError);
});

test('SQL text values that resemble timestamps remain text', async () => {
  const text = '2026-10-05T01:02:03.000Z';
  await query({ query: async (_, values) => {
    assert.equal(values.p1, text);
    return [{ affectedRows:1, insertId:12 }];
  } }, 'INSERT INTO notes(value) VALUES(:p1)', [text]);
});

for (const fail of [false, true]) test(`transaction ${fail ? 'rolls back after error' : 'commits'} and releases connection`, async () => {
  const events = [];
  const connection = {
    beginTransaction: async () => events.push('begin'),
    query: async () => { events.push('query'); return [{ affectedRows:1 }]; },
    commit: async () => events.push('commit'),
    rollback: async () => events.push('rollback'),
    release: () => events.push('release'),
  };
  const run = runTransaction({ getConnection:async () => connection }, async client => {
    await client.query('INSERT INTO entries(id) VALUES(:p1)', ['test']);
    if (fail) throw new Error('constraint failed');
    return 'saved';
  });
  if (fail) await assert.rejects(run, /constraint failed/);
  else assert.equal(await run, 'saved');
  assert.deepEqual(events, ['begin','query',fail ? 'rollback' : 'commit','release']);
});
