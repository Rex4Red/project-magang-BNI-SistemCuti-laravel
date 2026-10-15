import {spawn,spawnSync} from 'node:child_process';
import {existsSync,mkdirSync,writeFileSync,unlinkSync} from 'node:fs';
import {resolve} from 'node:path';

mkdirSync('.runtime',{recursive:true});
const database=resolve('.runtime/e2e.sqlite');
if(existsSync(database))unlinkSync(database);
writeFileSync(database,'');
const php=process.env.PHP_BINARY??(existsSync('.tools/php/php.exe')?resolve('.tools/php/php.exe'):'php');
const cache=resolve('.runtime/e2e-cache',Date.now().toString());
const env={...process.env,APP_ENV:'testing',APP_URL:'http://127.0.0.1:5174',APP_DEBUG:'true',DB_CONNECTION:'sqlite',DB_DATABASE:database,DB_URL:'',DEMO_MODE:'true',CUTI_MAIL_MODE:'capture',CACHE_STORE:'file',CACHE_FILE_PATH:cache,CACHE_LOCK_PATH:cache+'/locks',SESSION_DRIVER:'file'};
for(const args of [['artisan','migrate','--force','--no-interaction'],['artisan','cuti:setup','--demo','--no-interaction']]){
  const result=spawnSync(php,args,{env,stdio:'inherit'});
  if(result.status!==0)process.exit(result.status??1);
}
const server=spawn(php,['artisan','serve','--host=127.0.0.1','--port=3011','--no-reload'],{env,stdio:'inherit'});
const vite=spawn(process.execPath,['node_modules/vite/bin/vite.js','--host','127.0.0.1','--port','5174','--strictPort'],{env:{...process.env,API_TARGET:'http://127.0.0.1:3011'},stdio:'inherit'});
const children=[server,vite];
let shuttingDown=false;
function stop(code=0){
  if(shuttingDown)return;shuttingDown=true;
  for(const child of children){
    if(process.platform==='win32'&&child.pid)spawnSync('taskkill',['/PID',String(child.pid),'/T','/F'],{stdio:'ignore'});
    else child.kill('SIGTERM');
  }
  process.exit(code);
}
process.on('SIGINT',()=>stop());process.on('SIGTERM',()=>stop());
for(const child of children)child.on('exit',code=>stop(code??1));
