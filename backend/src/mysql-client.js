// Named parameters preserve repeated/out-of-order bindings without concatenating SQL.
function parameter(value) {
  if (Array.isArray(value)) return value.length ? value.map(parameter) : [null];
  if (value && typeof value === 'object' && !(value instanceof Date) && !Buffer.isBuffer(value)) {
    throw new TypeError('Parameter SQL harus berupa nilai scalar, Date, Buffer, atau array.');
  }
  return value ?? null;
}

export async function query(connection, sql, params = []) {
  const values = Object.fromEntries(params.map((value, index) => [`p${index + 1}`, parameter(value)]));
  const [result] = await connection.query(sql, values);
  if (!Array.isArray(result)) return { rows: [], rowCount: result.affectedRows, insertId: result.insertId };
  for (const row of result) {
    // MariaDB returns JSON as text; MySQL returns parsed JSON.
    for (const key of ['permissions', 'scores']) {
      if (typeof row[key] === 'string') row[key] = JSON.parse(row[key]);
    }
    for (const key of ['active', 'archived']) {
      if (key in row) row[key] = Boolean(row[key]);
    }
  }
  return { rows: result, rowCount: result.length };
}

export async function runTransaction(driver, fn) {
  const client = await driver.getConnection();
  try {
    await client.beginTransaction();
    const result = await fn({ query: (sql, params) => query(client, sql, params) });
    await client.commit();
    return result;
  } catch (error) {
    await client.rollback();
    throw error;
  } finally {
    client.release();
  }
}
