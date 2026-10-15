import {test,expect} from '@playwright/test';
import {DEFAULT_CALENDAR} from '../../shared/domain';

for(const category of ['reguler','darurat']){
  test(`kalender cuti ${category} memblokir H-3 setelah berpindah bulan`,async({page},testInfo)=>{
    await page.route('**/api/dashboard?*',async route=>{
      const response=await route.fetch();
      const data=await response.json();
      await route.fulfill({response,json:{...data,today:'2026-10-15',calendarConfig:DEFAULT_CALENDAR}});
    });
    await page.goto('/');
    await page.getByRole('button',{name:'Karyawan',exact:true}).click();
    await page.getByRole('button',{name:'Masuk',exact:true}).click();
    await page.locator('nav').getByRole('button',{name:'Ajukan cuti',exact:true}).click();
    if(category==='darurat')await page.getByRole('button',{name:/Cuti darurat/}).click();
    await page.getByLabel('Tanggal mulai',{exact:true}).click();
    const startPicker=page.getByRole('dialog',{name:'Pilih Tanggal mulai',exact:true});
    for(let i=0;i<(category==='reguler'?1:2);i++)await startPicker.getByRole('button',{name:'Bulan berikutnya'}).click();
    await expect(startPicker.locator('.datepicker-title')).toHaveText('Desember 2026');
    for(const day of [29,30,31]){
      const button=startPicker.getByRole('button',{name:String(day),exact:true});
      await expect(button).toBeDisabled();
      await expect(button).toHaveAttribute('title','3 hari kerja terakhir bulan (H-3)');
    }
    await expect(startPicker.getByRole('button',{name:'28',exact:true})).toBeEnabled();
    await startPicker.getByRole('button',{name:'Bulan berikutnya'}).click();
    await expect(startPicker.locator('.datepicker-title')).toHaveText('Januari 2027');
    for(const day of [27,28,29,30,31])await expect(startPicker.getByRole('button',{name:String(day),exact:true})).toBeDisabled();
    await expect(startPicker.getByRole('button',{name:'26',exact:true})).toBeEnabled();
    await startPicker.getByRole('button',{name:'Bulan sebelumnya'}).click();
    await startPicker.getByRole('button',{name:'28',exact:true}).click();
    await expect(page.getByLabel('Tanggal mulai',{exact:true})).toHaveValue('28/12/2026');
    await expect(page.getByLabel('Tanggal akhir',{exact:true})).toHaveValue('28/12/2026');
    await page.getByLabel('Tanggal akhir',{exact:true}).click();
    const endPicker=page.getByRole('dialog',{name:'Pilih Tanggal akhir',exact:true});
    for(const day of [29,30,31]){
      const button=endPicker.getByRole('button',{name:String(day),exact:true});
      await expect(button).toBeDisabled();
      await expect(button).toHaveAttribute('title','3 hari kerja terakhir bulan (H-3)');
    }
    await page.keyboard.press('Escape');
    await page.setViewportSize({width:390,height:844});
    await page.getByLabel('Tanggal mulai',{exact:true}).click();
    await expect(startPicker.getByRole('button',{name:'30',exact:true})).toBeDisabled();
    await startPicker.scrollIntoViewIfNeeded();
    await page.screenshot({path:testInfo.outputPath('blocked-dates-mobile.png'),animations:'disabled'});
  });
}

test('kalender pemilih tanggal mengikuti pengecualian hari kerja saat menghitung H-3',async({page})=>{
  const calendar={...DEFAULT_CALENDAR,exceptions:{...DEFAULT_CALENDAR.exceptions,'2026-12-31':{working:false,label:'Libur unit pengujian'}}};
  await page.route('**/api/dashboard?*',async route=>{
    const response=await route.fetch();
    const data=await response.json();
    await route.fulfill({response,json:{...data,today:'2026-10-15',calendarConfig:calendar}});
  });
  await page.goto('/');
  await page.getByRole('button',{name:'Karyawan',exact:true}).click();
  await page.getByRole('button',{name:'Masuk',exact:true}).click();
  await page.locator('nav').getByRole('button',{name:'Ajukan cuti',exact:true}).click();
  await page.getByLabel('Tanggal mulai',{exact:true}).click();
  const picker=page.getByRole('dialog',{name:'Pilih Tanggal mulai',exact:true});
  await picker.getByRole('button',{name:'Bulan berikutnya'}).click();
  for(const day of [28,29,30]){
    const button=picker.getByRole('button',{name:String(day),exact:true});
    await expect(button).toBeDisabled();
    await expect(button).toHaveAttribute('title','3 hari kerja terakhir bulan (H-3)');
  }
  await expect(picker.getByRole('button',{name:'31',exact:true})).toBeDisabled();
  await expect(picker.getByRole('button',{name:'31',exact:true})).toHaveAttribute('title','Hari libur: Libur unit pengujian');
  await expect(picker.getByRole('button',{name:'25',exact:true})).toBeEnabled();
});
