import 'dotenv/config';
import bcrypt from 'bcryptjs';
import { pool } from './db.js';
import { defaultPermissions } from './domain.js';

const username = process.env.DEV_ADMIN_USERNAME;
const password = process.env.DEV_ADMIN_PASSWORD;
if (!username || !password) throw new Error('DEV_ADMIN_USERNAME dan DEV_ADMIN_PASSWORD wajib tersedia di .env.');

await pool.query(
  `INSERT INTO users(username,password_hash,password_scheme,name,role,active,permissions)
   VALUES(:p1,:p2,'bcrypt','Administrator Lokal','superuser',true,:p3)
   ON DUPLICATE KEY UPDATE password_hash=:p2,password_scheme='bcrypt',active=true,permissions=:p3`,
  [username, await bcrypt.hash(password, 12), JSON.stringify(defaultPermissions('superuser'))],
);
console.log(`Akun development ${username} siap.`);
await pool.end();
