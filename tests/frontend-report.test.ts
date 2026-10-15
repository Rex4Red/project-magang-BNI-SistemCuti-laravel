import {describe,expect,it} from 'vitest';
import {buildPdfReport} from '../src/reportPdf';
import type {Employee,Leave} from '../shared/domain';

const actor:Employee={id:'sdm',name:'Petugas SDM Uji',email:'sdm@example.test',phone:'081234567890',position:'SDM',roles:['SDM'],unit:'KC01',active:true};
const leave:Leave={id:'one',number:'CT-PDF-001',employeeId:'staff',employeeName:'Pegawai Uji',position:'CS_BINA',unit:'KC01',category:'REGULAR',subtype:'Keluarga',reason:'ALASAN PRIVAT TIDAK BOLEH DIEKSPOR',start:'2026-11-02',end:'2026-11-05',email:'private@example.test',phone:'081234567891',days:['2026-11-02','2026-11-03','2026-11-04','2026-11-05'],effectiveStart:'2026-11-02',effectiveEnd:'2026-11-05',duration:4,createdAt:'2026-10-01T00:00:00Z',submittedAt:'2026-10-15T06:07:08Z',status:'PENDING_SDM',confirmation:'NOT_CONFIRMED',version:1,calendarVersion:1,limit:2,events:[]};

describe('Laporan PDF monitoring',()=>{
  it('menghasilkan PDF A4 portrait dengan waktu WIB dan tanpa alasan atau kontak pribadi',()=>{
    const doc=buildPdfReport({rows:[leave],actor,month:'all',unitName:'Cabang Uji',positionLabelFn:()=> 'CS BINA'});
    expect(doc.internal.pageSize.getWidth()).toBeCloseTo(210,0);
    expect(doc.internal.pageSize.getHeight()).toBeCloseTo(297,0);
    const pdf=doc.output();
    expect(pdf.startsWith('%PDF-')).toBe(true);
    expect(pdf).toContain('CT-PDF-001');
    expect(pdf).toContain('13:07:08 WIB');
    expect(pdf).not.toContain(leave.reason);
    expect(pdf).not.toContain(leave.email);
  });
  it('menomori semua halaman menggunakan jumlah akhir halaman laporan',()=>{
    const rows=Array.from({length:85},(_,index)=>({...leave,id:String(index),number:'CT-PDF-'+index,employeeName:'Nama Pegawai Pengujian Laporan Panjang '+index}));
    const doc=buildPdfReport({rows,actor,month:'2026-11',unitName:'Cabang Uji Dengan Nama Panjang Untuk Memastikan Teks Kop Surat Tetap Terbaca'});
    const count=doc.getNumberOfPages();
    expect(count).toBeGreaterThan(1);
    const pdf=doc.output();
    for(let page=1;page<=count;page++)expect(pdf).toContain(`Halaman ${page} dari ${count}`);
  });
  it('tetap dapat diunduh untuk periode tanpa pengajuan',()=>{
    const doc=buildPdfReport({rows:[],actor,month:'2026-11',unitName:'Cabang Uji'});
    expect(doc.getNumberOfPages()).toBe(1);
    expect(doc.output()).toContain('Total: 0 data');
  });
});
