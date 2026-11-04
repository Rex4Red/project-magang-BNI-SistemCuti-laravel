import {useEffect, useRef, useState, type ComponentType, type FormEvent, type ReactNode} from 'react';
import {CheckCircle2} from 'lucide-react';
import {addMonths, getValidEndDates, type Calendar, type LeaveInput, type PositionDefinition, type Preview} from '../shared/domain';
import {action, api} from './api';

interface FormUnit { id:string; name:string; positions:PositionDefinition[]; calendar:Calendar; outlets:{id:string;name:string}[]; }
interface FormConfig { today:string; units:FormUnit[]; }
interface Receipt { number:string; status:string; effectiveStart:string; effectiveEnd:string; duration:number; }
type DateField = ComponentType<{label:string;value:string;onChange:(value:string)=>void;min?:string;max?:string;calendar?:Calendar;validDates?:string[];disableNonWorking?:boolean;blockMonthEnd?:boolean}>;
const Field = ({label, children}:{label:string;children:ReactNode}) => <label className="field"><span>{label}</span>{children}</label>;
const formatDate = (date:string) => new Intl.DateTimeFormat('id-ID',{day:'numeric',month:'long',year:'numeric'}).format(new Date(date+'T00:00:00'));

export function PublicLeaveForm({DatePicker, onAdmin}:{DatePicker:DateField;onAdmin:()=>void}) {
  const [config,setConfig] = useState<FormConfig|null>(null);
  const [loadError,setLoadError] = useState('');
  const [reload,setReload] = useState(0);
  const [identity,setIdentity] = useState({name:'',email:'',phone:'',position:'',outletId:''});
  const [input,setInput] = useState<LeaveInput>({category:'REGULAR',subtype:'',reason:'',start:'',end:'',email:'',phone:''});
  const [preview,setPreview] = useState<Preview|null>(null);
  const [error,setError] = useState('');
  const [checking,setChecking] = useState(false);
  const [busy,setBusy] = useState(false);
  const [accepted,setAccepted] = useState(false);
  const [receipt,setReceipt] = useState<Receipt|null>(null);
  const key = useRef<string|null>(null);
  const revision = useRef(0);
  const currentUnit = config?.units.find(unit=>unit.outlets.some(outlet=>outlet.id===identity.outletId));
  const payload = {...input,...identity};
  const complete = identity.name.trim().length>=2 && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identity.email) && /^\+?[\d ()-]{8,20}$/.test(identity.phone) && identity.position && identity.outletId && input.reason.trim().length>=10 && input.subtype.trim().length>=2 && input.start && input.end;
  const minimum = config ? input.category==='REGULAR' ? addMonths(config.today,1) : config.today : '';
  const endDates = input.start && currentUnit ? getValidEndDates(input.start,currentUnit.calendar) : [];

  useEffect(()=>{
    let active=true;
    setLoadError('');
    api<FormConfig>('/public/form').then(data=>{if(active)setConfig(data);}).catch(e=>{if(active)setLoadError(e.message);});
    return()=>{active=false;};
  },[reload]);
  useEffect(()=>{
    const current=++revision.current;
    setPreview(null);setError('');setAccepted(false);
    if(!complete){setChecking(false);return;}
    setChecking(true);
    const timer=setTimeout(()=>{
      api<Preview>('/public/preview',{method:'POST',body:payload}).then(result=>{
        if(revision.current===current)setPreview(result);
      }).catch(e=>{if(revision.current===current)setError(e.message);}).finally(()=>{if(revision.current===current)setChecking(false);});
    },550);
    return()=>{clearTimeout(timer);revision.current++;};
  },[identity,input,complete]);

  function updateIdentity(field:keyof typeof identity,value:string) {
    key.current=null;
    setIdentity(previous=>({...previous,[field]:value,...(field==='outletId'?{position:''}:{})}));
    if(field==='outletId')setInput(previous=>({...previous,start:'',end:''}));
  }
  function updateInput(field:keyof LeaveInput,value:string) {
    key.current=null;
    setInput(previous=>({...previous,[field]:value,...(field==='category'?{subtype:'',start:'',end:''}:{}),...(field==='start'?{end:value}:{})}));
  }
  async function submit(event:FormEvent) {
    event.preventDefault();
    if(!preview || preview.errors.length || checking || busy)return;
    setBusy(true);setError('');
    key.current??=crypto.randomUUID();
    try {
      const result=await action('/public/requests',{...payload,fingerprint:preview.fingerprint,acceptAdjustment:accepted},key.current);
      setReceipt(result);window.scrollTo({top:0,behavior:'smooth'});
    } catch(e) {
      setError((e as Error).message);
      key.current=null;
      try{setPreview(await api('/public/preview',{method:'POST',body:payload}));}catch{}
    } finally {setBusy(false);}
  }

  return <div className="public-page">
    <header className="public-header"><a href="/" aria-label="Formulir pengajuan cuti"><span className="bni-logo"><img src="/images/bni-logo.png" alt="BNI" width="1080" height="782"/></span></a><a href="#login" onClick={onAdmin}>Masuk admin</a></header>
    <main className="public-main">
      {receipt ? <section className="public-sheet public-success" aria-labelledby="receipt-title">
        <CheckCircle2 size={38} aria-hidden="true"/>
        <h1 id="receipt-title">Cuti berhasil dicatat</h1>
        <dl><dt>Nomor pengajuan</dt><dd><strong>{receipt.number}</strong></dd><dt>Tanggal cuti</dt><dd>{formatDate(receipt.effectiveStart)} s.d. {formatDate(receipt.effectiveEnd)}</dd><dt>Durasi</dt><dd>{receipt.duration} hari kerja</dd><dt>Status</dt><dd>Tercatat</dd></dl>
        <button className="button secondary" onClick={()=>{setReceipt(null);setInput({...input,subtype:'',reason:'',start:'',end:''});setPreview(null);key.current=null;}}>Buat pengajuan lain</button>
      </section> : <section className="public-sheet" aria-labelledby="public-title">
        <div className="public-intro"><h1 id="public-title">Pengajuan cuti</h1><p>Isi formulir berikut untuk mencatat jadwal cuti Anda.</p></div>
        {loadError ? <div className="alert danger" role="alert">{loadError}<button type="button" onClick={()=>setReload(value=>value+1)}>Coba lagi</button></div> : !config ? <p role="status">Memuat formulir cuti…</p> : !config.units.some(unit=>unit.outlets.length&&unit.positions.length) ? <p>Formulir belum tersedia. Hubungi admin untuk mengatur outlet dan posisi.</p> :
        <form onSubmit={submit}>
          <fieldset disabled={busy} className="public-fields">
            <section className="public-section"><h2>Data pemohon</h2>
              <Field label="Nama lengkap"><input required minLength={2} maxLength={100} autoComplete="name" value={identity.name} onChange={e=>updateIdentity('name',e.target.value)}/></Field>
              <div className="public-grid"><Field label="Email"><input type="email" required maxLength={200} autoComplete="email" value={identity.email} onChange={e=>updateIdentity('email',e.target.value)}/></Field><Field label="Nomor HP"><input type="tel" required minLength={8} maxLength={20} autoComplete="tel" value={identity.phone} onChange={e=>updateIdentity('phone',e.target.value)}/></Field></div>
              <div className="public-grid"><Field label="Outlet"><select aria-label="Outlet" required value={identity.outletId} onChange={e=>updateIdentity('outletId',e.target.value)}><option value="">Pilih outlet</option>{config.units.map(unit=><optgroup key={unit.id} label={unit.name}>{unit.outlets.map(outlet=><option key={outlet.id} value={outlet.id}>{outlet.name}</option>)}</optgroup>)}</select></Field><Field label="Posisi"><select aria-label="Posisi" required disabled={!currentUnit} value={identity.position} onChange={e=>updateIdentity('position',e.target.value)}><option value="">Pilih posisi</option>{currentUnit?.positions.map(([code,label])=><option key={code} value={code}>{label}</option>)}</select></Field></div>
              <p className="public-note">Gunakan email yang sama untuk setiap pengajuan agar kuota bulanan tetap tercatat untuk satu orang.</p>
            </section>
            <section className="public-section"><h2>Rencana cuti</h2>
              <fieldset className="public-category"><legend>Kategori cuti</legend>{[['REGULAR','Reguler'],['EMERGENCY','Darurat']].map(([value,label])=><label key={value}><input type="radio" name="category" checked={input.category===value} onChange={()=>updateInput('category',value)}/>{label}</label>)}</fieldset>
              <Field label="Jenis cuti"><input aria-label="Jenis cuti" required minLength={2} maxLength={120} list="public-leave-types" value={input.subtype} onChange={e=>updateInput('subtype',e.target.value)} placeholder={input.category==='EMERGENCY'?'Cuti sakit, cuti duka, atau lainnya':'Keperluan keluarga, cuti tahunan, atau lainnya'}/><datalist id="public-leave-types">{(input.category==='EMERGENCY'?['Cuti sakit','Cuti duka']:['Cuti tahunan','Keperluan keluarga']).map(value=><option key={value} value={value}/>)}</datalist></Field>
              <Field label="Alasan pengajuan"><textarea aria-label="Alasan pengajuan" required minLength={10} maxLength={2000} rows={3} value={input.reason} onChange={e=>updateInput('reason',e.target.value)}/><small>Minimal 10 karakter.</small></Field>
              <p className="public-rule">{input.category==='REGULAR'?`Tanggal mulai paling awal ${formatDate(minimum)}.`:'Cuti darurat tidak perlu menunggu satu bulan.'} Maksimal 5 hari kerja. Tiga hari kerja terakhir bulan tidak dapat dipilih.</p>
              <div className="public-grid"><Field label="Tanggal mulai"><DatePicker label="Tanggal mulai" value={input.start} min={minimum} calendar={currentUnit?.calendar} disableNonWorking blockMonthEnd onChange={value=>updateInput('start',value)}/></Field><Field label="Tanggal akhir"><DatePicker label="Tanggal akhir" value={input.end} min={input.start||minimum} calendar={currentUnit?.calendar} disableNonWorking blockMonthEnd validDates={endDates} onChange={value=>updateInput('end',value)}/></Field></div>
            </section>
            <div className="public-result" aria-live="polite">
              {checking ? <p role="status">Memeriksa jadwal dan kuota…</p> : preview ? <><div className="public-duration"><strong>{preview.duration} hari kerja</strong><span>{preview.quota.available} dari {preview.quota.limit} slot posisi tersedia</span></div>{preview.adjusted&&preview.effectiveEnd&&<label className="checkbox public-adjustment"><input type="checkbox" checked={accepted} onChange={e=>setAccepted(e.target.checked)}/>Saya setuju tanggal akhir disesuaikan menjadi {formatDate(preview.effectiveEnd)} mengikuti kalender kerja dan batas akhir bulan.</label>}{preview.errors.map(issue=><p className="public-error" key={issue.code}>{issue.message}</p>)}</> : <p>Durasi dan ketersediaan kuota dihitung setelah formulir dilengkapi.</p>}
            </div>
            {error&&<div className="alert danger" role="alert">{error}</div>}
            <div className="public-submit"><button className="button primary" disabled={busy||checking||!preview||preview.errors.length>0||(preview.adjusted&&!accepted)}>{busy?'Mengirim…':'Kirim pengajuan'}</button></div>
          </fieldset>
        </form>}
      </section>}
    </main>
    <footer className="public-footer">BNI · Pengajuan cuti karyawan</footer>
  </div>;
}
