import {useEffect, useState, type CSSProperties} from 'react';
import {ArrowDownToLine, FileSpreadsheet} from 'lucide-react';
import {api} from './api';
import {useDirectory} from './DirectoryContext';

type Day = {date:string; number:number; off:boolean; label:string};
type Cell = {active:boolean; text:string};
type Row = {id:string; name:string; npp:string; position:string; unit:string; replacement:string; status:'APPROVED'|'PENDING_SDM'|'RECORDED'; cells:Cell[]};
type Summary = {month:string; monthLabel:string; unitName:string; days:Day[]; rows:Row[]};

export function CalendarSummary({month,recordOnly=false}:{month:string;recordOnly?:boolean}) {
  const {positions} = useDirectory();
  const [position, setPosition] = useState('ALL');
  const [data, setData] = useState<Summary|null>(null);
  const [error, setError] = useState('');
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    let alive = true;
    let fetching = false;
    setData(null);
    setError('');
    const load = async () => {
      if (fetching) return;
      fetching = true;
      try {
        const latest = await api<Summary>(`/leave-calendar?${new URLSearchParams({month, position})}`);
        if (alive) {setData(latest); setError('');}
      } catch (failure) {
        if (alive) setError((failure as Error).message);
      } finally {fetching = false;}
    };
    void load();
    const timer = setInterval(() => {if (document.visibilityState === 'visible') void load();}, 5000);
    return () => {alive = false; clearInterval(timer);};
  }, [month, position, retry]);

  return <section className="panel leave-summary-panel">
    <div className="panel-heading summary-toolbar">
      <div><h2>Rekap kalender cuti pegawai</h2><p>Satu baris per pengajuan cuti aktif pada bulan yang dipilih.</p></div>
      <div className="summary-actions">
        <select aria-label="Filter posisi rekap" value={position} onChange={event => setPosition(event.target.value)}>
          <option value="ALL">Semua posisi</option>{positions.map(([code, label]) => <option key={code} value={code}>{label}</option>)}
        </select>
        {data && !error && <a className="button primary" href={`/api/leave-calendar.xlsx?${new URLSearchParams({month, position})}`}><ArrowDownToLine size={16}/>Ekspor Excel</a>}
      </div>
    </div>
    {error && <div className="alert danger margin" role="alert">{error}<button type="button" className="text-button" onClick={() => setRetry(value => value + 1)}>Coba lagi</button></div>}
    {!data && !error && <p className="summary-loading" role="status">Memuat rekap bulanan…</p>}
    {data && <>
      <div className="summary-period"><FileSpreadsheet size={17}/><strong>{data.monthLabel}</strong><span>{data.unitName}</span><small>{data.rows.length} pengajuan</small></div>
      <div className="leave-summary-scroll" role="region" aria-label="Tabel rekap kalender cuti" tabIndex={0}>
        <table className="leave-summary-table" style={{'--summary-day-count': data.days.length} as CSSProperties}>
          <caption className="sr-only">Rekap cuti {data.monthLabel}: nama pegawai, NPP, posisi, unit, tanggal cuti, dan pengganti.</caption>
          <colgroup><col className="summary-number"/><col className="summary-name"/><col className="summary-npp"/><col className="summary-position"/><col className="summary-unit"/>{data.days.map(day => <col key={day.date} className="summary-day"/>)}<col className="summary-replacement"/></colgroup>
          <thead><tr>
            {['NO', 'NAMA PEGAWAI', 'NPP', 'POSISI', 'UNIT'].map((label, index) => <th key={label} rowSpan={2} scope="col" className={index < 2 ? `summary-fixed summary-fixed-${index}` : ''}>{label}</th>)}
            {data.days.map(day => <th key={day.date} scope="col" className={day.off ? 'summary-off' : ''} title={`${day.date}${day.label ? ' · ' + day.label : ''}`}>{day.number}</th>)}
            <th rowSpan={2} scope="col">PENGGANTI / PGS</th>
          </tr><tr><th colSpan={data.days.length} scope="colgroup" className="summary-month">{data.monthLabel}</th></tr></thead>
          <tbody>{data.rows.map((row, rowIndex) => <tr key={row.id}>
            <td className="summary-fixed summary-fixed-0">{rowIndex + 1}</td><th scope="row" className="summary-fixed summary-fixed-1">{row.name}</th><td>{row.npp || '—'}</td><td>{row.position}</td><td>{row.unit}</td>
            {row.cells.map((cell, index) => {
              if (cell.active && row.cells[index - 1]?.active) return null;
              let span = 1;
              if (cell.active) {while (row.cells[index + span]?.active) span++;}
              const day = data.days[index];
              return <td key={day.date} colSpan={span} className={day.off ? 'summary-off' : cell.active ? (row.status === 'PENDING_SDM' ? 'summary-pending' : 'summary-approved') : 'summary-blank'} title={cell.active ? `${day.date} – ${data.days[index + span - 1].date} · ${row.status === 'PENDING_SDM' ? 'Menunggu review' : row.status === 'RECORDED' ? 'Tercatat' : 'Disetujui'} · ${cell.text}` : `${day.date} · ${day.label || (day.off ? 'Hari libur' : 'Hari kerja')}`}><span>{cell.text}</span></td>;
            })}
            <td>{row.replacement}</td>
          </tr>)}</tbody>
        </table>
      </div>
      {!data.rows.length && <div className="empty"><FileSpreadsheet size={28}/><h3>Belum ada cuti aktif</h3><p>Tidak ada cuti aktif untuk bulan dan posisi ini.</p></div>}
      <div className="summary-legend"><span><i className="summary-approved"/>{recordOnly?'Tercatat':'Disetujui'}</span>{!recordOnly&&<span><i className="summary-pending"/>Menunggu review</span>}<span><i className="summary-off"/>Hari libur</span><span>Isi blok: pengganti / jenis cuti. Geser tabel untuk melihat seluruh tanggal.</span></div>
    </>}
  </section>;
}
