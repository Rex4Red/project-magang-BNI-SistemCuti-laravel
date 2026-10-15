import {createRequire} from 'node:module';
import {resolve,dirname} from 'node:path';
import {pathToFileURL} from 'node:url';
import {mkdirSync,writeFileSync,existsSync} from 'node:fs';

const source=process.argv[2],output=process.argv[3];
if(!source||!output)throw new Error('Usage: node scripts/export-legacy.mjs <old-project-folder> <private-output.json>');
const oldRoot=resolve(source),file=resolve(output);
const requireOld=createRequire(resolve(oldRoot,'package.json'));
let client;
if(process.env.LEGACY_DATABASE_URL){
  const {Client}=requireOld('pg');client=new Client({connectionString:process.env.LEGACY_DATABASE_URL});await client.connect();
}else{
  const database=resolve(oldRoot,'.data/postgres');
  if(!existsSync(database))throw new Error('Legacy database not found. Stop the old app before opening its PGlite database.');
  const {PGlite}=await import(pathToFileURL(requireOld.resolve('@electric-sql/pglite')).href);
  client=new PGlite(database);await client.waitReady;
}
try{
  await client.query('BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
  const tables={};
  for(const table of ['units','employees','outlets','requests','audit','mail_jobs','mail_deliveries']){
    const result=await client.query('SELECT * FROM '+table);tables[table]=result.rows;
  }
  await client.query('COMMIT');
  mkdirSync(dirname(file),{recursive:true});
  writeFileSync(file,JSON.stringify({format:'bni-cuti-legacy-v1',exportedAt:new Date().toISOString(),tables},null,2),{mode:0o600,flag:'wx'});
  process.stdout.write('Private export created: '+tables.employees.length+' employees, '+tables.requests.length+' requests. Do not commit this file.\n');
}finally{
  if(typeof client.end==='function')await client.end();else await client.close();
}
