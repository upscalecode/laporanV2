import 'dotenv/config';
import fs from 'node:fs';
import path from 'node:path';
import ExcelJS from 'exceljs';
import { pool, transaction } from './db.js';

const file = process.argv[2];
if (!file || !fs.existsSync(file)) {
  console.error('Pemakaian: npm run db:import -- "C:\\path\\Laporan Produksi.xlsx"');
  process.exit(1);
}

const workbook = new ExcelJS.Workbook();
await workbook.xlsx.readFile(file);
const cellValue = value => {
  if (value === null || value === undefined) return '';
  if (value instanceof Date) return value;
  if (typeof value !== 'object') return value;
  if ('result' in value) return value.result;
  if ('text' in value) return value.text;
  if (Array.isArray(value.richText)) return value.richText.map(item => item.text).join('');
  return String(value);
};
const rows = name => {
  const sheet = workbook.getWorksheet(name);
  if (!sheet || sheet.rowCount < 2) return [];
  const headers = sheet.getRow(1).values.slice(1).map(cellValue);
  const result = [];
  sheet.eachRow((row, rowNumber) => {
    if (rowNumber === 1) return;
    const item = {};
    headers.forEach((header, index) => { if (header) item[String(header)] = cellValue(row.getCell(index + 1).value); });
    if (Object.values(item).some(value => value !== '')) result.push(item);
  });
  return result;
};
const val = (row, ...keys) => { for (const key of keys) if (row[key] !== undefined) return row[key]; return ''; };
const number = value => Number.isFinite(Number(value)) ? Number(value) : 0;
const bool = value => value === true || ['true','1','ya','yes','aktif'].includes(String(value).trim().toLowerCase());
const iso = value => { if (!value) return null; const d = value instanceof Date ? value : new Date(value); return Number.isNaN(d.getTime()) ? null : d; };
const date = value => iso(value)?.toISOString().slice(0,10) || null;
const json = (value, fallback={}) => { try { return typeof value === 'object' ? value : JSON.parse(value || '{}'); } catch { return fallback; } };

const stats = {};
const count = name => stats[name] = (stats[name] || 0) + 1;

await transaction(async client => {
  for (const row of rows('Users')) {
    const username=String(val(row,'username','Username')).trim(); if(!username)continue;
    await client.query(`INSERT INTO users(username,password_hash,password_scheme,name,role,active,created_at,permissions) VALUES(:p1,:p2,'sha256-legacy',:p3,:p4,:p5,COALESCE(:p6,now()),:p7) ON DUPLICATE KEY UPDATE password_hash=:p2,name=:p3,role=:p4,active=:p5,permissions=:p7`,[username,String(val(row,'passwordHash','Password Hash')),String(val(row,'name','Nama')||username),String(val(row,'role','Role')||'user'),bool(val(row,'active','Aktif')),iso(val(row,'createdAt','Dibuat Pada')),JSON.stringify(json(val(row,'permissionsJson','Permissions'),{}))]);count('users');
  }
  const { rows:userRows }=await client.query('SELECT username FROM users'); const users=new Set(userRows.map(x=>x.username));
  const safeUser = value => users.has(String(value||'')) ? String(value) : null;

  const master=rows('Master'); const categories=[['operator','Nama Operator'],['produk','Nama Produk'],['botol','Nama Botol']];
  for(const [category,column] of categories){let position=0;for(const row of master){const value=String(val(row,column)).trim();if(!value)continue;await client.query('INSERT INTO master_values(category,value,position) VALUES(:p1,:p2,:p3) ON DUPLICATE KEY UPDATE value=:p2',[category,value,++position]);count('master_values');}}

  for(const row of rows('SPK')){const batch=String(val(row,'No Batch')).trim();if(!batch)continue;await client.query(`INSERT INTO spk(batch_no,tanggal,produk,botol,produksi_dus,qty_per_dus,qty,created_by,created_at,updated_at,update_count,status) VALUES(:p1,:p2,:p3,:p4,:p5,:p6,:p7,:p8,COALESCE(:p9,now()),:p10,:p11,:p12) ON DUPLICATE KEY UPDATE tanggal=:p2,produk=:p3,botol=:p4,produksi_dus=:p5,qty_per_dus=:p6,qty=:p7,updated_at=:p10,update_count=:p11,status=:p12`,[batch,date(val(row,'Tanggal')),String(val(row,'Nama Produk')),String(val(row,'Botol')),number(val(row,'Produksi (Dus)')),number(val(row,'Qty/Dus (PCS/DUS)')),number(val(row,'Total Qty (PCS)')),safeUser(val(row,'Dibuat Oleh')),iso(val(row,'Dibuat Pada')),iso(val(row,'Di-update Pada')),number(val(row,'Jumlah Update')),String(val(row,'Status')||'normal')]);count('spk');}

  for(const row of rows('Pengerjaan')){const id=String(val(row,'id')).trim();if(!id)continue;await client.query(`INSERT INTO entries(id,report_id,tab,tanggal,operator,produk,botol,qty_kardus,qty_botol_per_kardus,total_qty,botol_pecah_jenis,qty_botol_pecah,qty_kardus_basah,created_by,created_at,updated_at,update_count,sisa_press_tanggal_asal,keterangan) VALUES(:p1,:p2,:p3,:p4,:p5,:p6,:p7,:p8,:p9,:p10,:p11,:p12,:p13,:p14,COALESCE(:p15,now()),:p16,:p17,:p18,:p19) ON DUPLICATE KEY UPDATE report_id=:p2,tab=:p3,tanggal=:p4,operator=:p5,produk=:p6,botol=:p7,qty_kardus=:p8,qty_botol_per_kardus=:p9,total_qty=:p10,qty_botol_pecah=:p12,qty_kardus_basah=:p13,updated_at=:p16,update_count=:p17,keterangan=:p19`,[id,String(val(row,'reportId')),String(val(row,'tab')),date(val(row,'tanggal')),String(val(row,'operator')),String(val(row,'produk')),String(val(row,'botol')),number(val(row,'qtyKardus')),number(val(row,'qtyBotolPerKardus')),number(val(row,'totalQty')),String(val(row,'botolPecahJenis')),number(val(row,'qtyBotolPecah')),number(val(row,'qtyKardusBasah')),safeUser(val(row,'createdBy')),iso(val(row,'createdAt')),iso(val(row,'updatedAt')),number(val(row,'updateCount')),String(val(row,'sisaPressTanggalAsal')),String(val(row,'keterangan'))]);count('entries');}

  for(const sheetName of ['Arsip Penutupan Press','Penutupan Press'])for(const row of rows(sheetName)){const id=String(val(row,'id')).trim();if(!id)continue;await client.query(`INSERT INTO press_adjustments(id,tanggal,produk,botol,qty_ditutup,alasan,closed_by,closed_by_name,created_at,qty_botol_per_kardus,target_batch_no,target_tanggal_asal,archived) VALUES(:p1,:p2,:p3,:p4,:p5,:p6,:p7,:p8,COALESCE(:p9,now()),:p10,:p11,:p12,:p13) ON DUPLICATE KEY UPDATE id=id`,[id,date(val(row,'tanggal')),String(val(row,'produk')),String(val(row,'botol')),number(val(row,'qtyDitutup')),String(val(row,'alasan')),String(val(row,'closedBy')),String(val(row,'closedByName')),iso(val(row,'createdAt')),number(val(row,'qtyBotolPerKardus')),String(val(row,'targetBatchNo')),String(val(row,'targetTanggalAsal')),sheetName.startsWith('Arsip')]);count('press_adjustments');}

  for(const row of rows('APD')){const id=String(val(row,'apdId')).trim();if(!id)continue;const scores={maskerTidakSesuai:number(val(row,'Masker tidak sesuai')),lenganDitarik:number(val(row,'Lengan ditarik ke atas')),sepatuDiinjak:number(val(row,'Sepatu diinjak')),rambutKelihatan:number(val(row,'Rambut kelihatan')),resletingTidakPenuh:number(val(row,'APD tidak diresleting penuh')),memakaiAksesoris:number(val(row,'Memakai aksesoris')),kebersihanSepatu:number(val(row,'Kebersihan Sepatu'))};await client.query(`INSERT INTO apd_entries(id,tanggal,operator,scores,total_points,percentage,alasan,created_by,created_at,updated_at) VALUES(:p1,:p2,:p3,:p4,:p5,:p6,:p7,:p8,COALESCE(:p9,now()),:p10) ON DUPLICATE KEY UPDATE tanggal=:p2,operator=:p3,scores=:p4,total_points=:p5,percentage=:p6,alasan=:p7,updated_at=:p10`,[id,date(val(row,'Tanggal')),String(val(row,'Nama Operator')),JSON.stringify(scores),number(val(row,'Total Poin')),number(val(row,'Nilai Prosentase APD')),String(val(row,'Alasan')),safeUser(val(row,'createdBy')),iso(val(row,'createdAt')),iso(val(row,'updatedAt'))]);const apdRecord=await client.query('SELECT id FROM apd_entries WHERE id=:p1',[id]);if(!apdRecord.rowCount)throw new Error('Data APD untuk tanggal dan operator tersebut sudah ada dengan ID lain.');count('apd_entries');if(String(val(row,'photoFileId')).trim())count('apd_photo_references_not_imported');}

  for(const row of rows('Down Time')){const arrival=iso(val(row,'Kedatangan Racikan'));if(!arrival)continue;await client.query(`INSERT INTO downtime_entries(tanggal,production_start_time,arrival_timestamp,down_time,alasan,keterangan) VALUES(:p1,:p2,:p3,:p4,:p5,:p6) ON DUPLICATE KEY UPDATE production_start_time=:p2,arrival_timestamp=:p3,down_time=:p4,alasan=:p5,keterangan=:p6`,[arrival.toISOString().slice(0,10),String(val(row,'Waktu Masuk Kerja Produksi')||'00:00').slice(0,5),arrival,number(val(row,'Down Time')),String(val(row,'Alasan')),String(val(row,'Keterangan'))]);count('downtime_entries');}

  for(const row of rows('Settings')){const key=String(val(row,'key')).trim();if(!key)continue;await client.query('INSERT INTO settings(setting_key,value,updated_at,updated_by) VALUES(:p1,:p2,COALESCE(:p3,now()),:p4) ON DUPLICATE KEY UPDATE value=:p2,updated_at=COALESCE(:p3,now()),updated_by=:p4',[key,JSON.stringify(number(val(row,'value'))||String(val(row,'value'))),iso(val(row,'updatedAt')),String(val(row,'updatedBy'))]);count('settings');}
});

console.log(`Import selesai dari ${path.resolve(file)}`);
console.table(stats);
if(stats.apd_photo_references_not_imported)console.warn('Foto APD masih berada di Google Drive dan tidak ikut di dalam file XLSX. Data APD tetap diimpor tanpa gambar.');
await pool.end();
