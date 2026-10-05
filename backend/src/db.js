import 'dotenv/config';
import mysql from 'mysql2/promise';
import { query, runTransaction } from './mysql-client.js';

const url = new URL(process.env.DATABASE_URL || 'mysql://root@127.0.0.1:3306/laporan_produksi');
if (url.protocol !== 'mysql:') throw new Error('DATABASE_URL harus menggunakan mysql://. Lihat backend/.env.example.');
const driver = mysql.createPool({
  host: url.hostname,
  port: Number(url.port) || 3306,
  user: decodeURIComponent(url.username),
  password: decodeURIComponent(url.password),
  database: decodeURIComponent(url.pathname.slice(1)),
  charset: 'utf8mb4_bin',
  timezone: 'Z',
  dateStrings: ['DATE'],
  namedPlaceholders: true,
  connectionLimit: 10,
  ssl: process.env.DATABASE_SSL === 'true' ? { rejectUnauthorized: true } : undefined,
});

// Keep server-side NOW() and driver-side Date serialization in UTC.
driver.on('connection', connection => connection.query("SET time_zone = '+00:00'"));

export const pool = {
  query: (sql, params) => query(driver, sql, params),
  end: () => driver.end(),
};

export const transaction = fn => runTransaction(driver, fn);
