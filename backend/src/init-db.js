import fs from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { pool } from './db.js';

const sqlUrl = new URL('../sql/001_schema.sql', import.meta.url);
try {
  // This schema contains only simple statements, with no routines or delimiters.
  const sql = (await fs.readFile(fileURLToPath(sqlUrl), 'utf8')).replace(/^--.*$/gm, '');
  for (const statement of sql.split(';').map(text => text.trim()).filter(Boolean)) {
    await pool.query(statement);
  }
  console.log('Skema MySQL siap.');
} finally {
  await pool.end();
}
