import {test,expect} from '@playwright/test';
import {readFile} from 'node:fs/promises';
import type {Leave} from '../../shared/domain';

test('SDM exports a portrait PDF, downloads Excel, and sorts submission times',async({page},testInfo)=>{
  await page.route('**/api/dashboard?*',async route=>{
    const response=await route.fetch();
    const data=await response.json();
    const seed=data.requests.find((r:Leave)=>r.status==='PENDING_SDM');
    const rows=Array.from({length:60},(_,index)=>({...seed,id:'report-'+index,number:'CT-REPORT-'+index,employeeName:'Pegawai Laporan '+index,submittedAt:new Date(Date.UTC(2026,9,1,1,0,index)).toISOString(),reason:'RAHASIA PDF UJI'}));
    rows.push({...seed,id:'report-latest',number:'CT-REPORT-LATEST',employeeName:'Pegawai Terbaru',submittedAt:'2026-10-15T06:07:08Z',reason:'RAHASIA PDF UJI'});
    await route.fulfill({response,json:{...data,requests:rows}});
  });
  await page.goto('/');
  await page.getByRole('button',{name:'SDM',exact:true}).click();
  await page.getByRole('button',{name:'Masuk',exact:true}).click();
  await page.getByRole('dialog',{name:'Pengajuan menunggu review'}).getByRole('button',{name:'Tutup',exact:true}).click();
  await expect(page.getByRole('link',{name:'Unduh laporan',exact:true})).toHaveCount(0);
  await page.locator('nav').getByRole('button',{name:/Pengajuan cuti/}).click();
  await expect(page.getByRole('columnheader',{name:'Waktu pengajuan',exact:true})).toBeVisible();
  const first=page.locator('tbody tr').first();
  await expect(first).toContainText('Pegawai Terbaru');
  await expect(first).toContainText('13:07:08 WIB');
  await page.getByLabel('Urutan waktu pengajuan').selectOption('earliest');
  await expect(first).toContainText('Pegawai Laporan 0');
  await page.getByRole('link',{name:'Unduh laporan',exact:true}).click();
  await expect(page.getByRole('menu')).toBeVisible();
  await page.keyboard.press('Escape');
  await expect(page.getByRole('menu')).toHaveCount(0);
  await page.setViewportSize({width:390,height:844});
  await page.getByRole('link',{name:'Unduh laporan',exact:true}).click();
  await expect.poll(()=>page.getByRole('menu').evaluate(el=>{const box=el.getBoundingClientRect();return box.left>=0&&box.right<=innerWidth;})).toBe(true);
  await page.screenshot({path:testInfo.outputPath('download-menu-mobile.png'),animations:'disabled'});
  const pdfEvent=page.waitForEvent('download');
  await page.getByRole('menuitem',{name:/Unduh PDF/}).click();
  const pdf=await pdfEvent;
  const pdfFile=testInfo.outputPath('monitoring-cuti-all.pdf');
  await pdf.saveAs(pdfFile);
  expect(pdf.suggestedFilename()).toBe('monitoring-cuti-all.pdf');
  const pdfBytes=await readFile(pdfFile);
  expect(pdfBytes.subarray(0,5).toString()).toBe('%PDF-');
  expect(pdfBytes.toString('latin1')).toContain('13:07:08 WIB');
  expect(pdfBytes.toString('latin1')).not.toContain('RAHASIA PDF UJI');
  await page.getByRole('link',{name:'Unduh laporan',exact:true}).click();
  const excel=page.getByRole('menuitem',{name:/Unduh Excel/});
  await expect(excel).toHaveAttribute('href','/api/reports.xlsx?month=all');
  const excelEvent=page.waitForEvent('download');
  await excel.click();
  const download=await excelEvent;
  const xlsx=testInfo.outputPath('monitoring-cuti-all.xlsx');
  await download.saveAs(xlsx);
  expect((await readFile(xlsx)).subarray(0,2).toString()).toBe('PK');
});

test('initialization retries a temporary failure and SDM can recover from a non-JSON response',async({page})=>{
  let configFailures=0;
  let dashboardFailures=0;
  await page.route('**/api/config',async route=>{
    if(configFailures++===0){await route.fulfill({status:503,contentType:'text/html',body:'<h1>Temporary gateway failure</h1>'});return;}
    await route.continue();
  });
  await page.route('**/api/dashboard?*',async route=>{
    if(dashboardFailures++===0){await route.fulfill({status:502,contentType:'text/html',body:'<h1>PRIVATE SERVER DETAIL</h1>'});return;}
    await route.continue();
  });
  await page.goto('/');
  await page.getByRole('button',{name:'SDM',exact:true}).click();
  await page.getByRole('button',{name:'Masuk',exact:true}).click();
  await expect(page.getByRole('alert')).toContainText('Respons server tidak dapat dibaca. Coba lagi.');
  await expect(page.getByRole('alert')).not.toContainText('PRIVATE SERVER DETAIL');
  await page.getByRole('button',{name:'Coba lagi',exact:true}).click();
  await expect(page.locator('.stats-grid')).toBeVisible();
  await page.getByRole('dialog',{name:'Pengajuan menunggu review'}).getByRole('button',{name:'Tutup',exact:true}).click();
  expect(configFailures).toBeGreaterThan(1);
  const data=await (await page.request.get('/api/dashboard?month=2026-11')).json();
  const leave=data.requests.find((r:Leave)=>r.status==='PENDING_SDM');
  await page.locator('nav').getByRole('button',{name:/Pengajuan cuti/}).click();
  await page.getByRole('button',{name:'Lihat '+leave.employeeName+' '+leave.number,exact:true}).click();
  await expect(page.getByRole('dialog').getByText('Waktu diajukan',{exact:true})).toBeVisible();
  await expect(page.getByRole('dialog').locator('dd').filter({hasText:/\d{2}:\d{2}:\d{2} WIB/})).toBeVisible();
});
