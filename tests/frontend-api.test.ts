import {afterEach,describe,expect,it,vi} from 'vitest';
import {api,ApiError} from '../src/api';

afterEach(()=>vi.unstubAllGlobals());

describe('Respons API saat koneksi terganggu',()=>{
  it('menampilkan kesalahan koneksi tanpa meneruskan detail jaringan',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockRejectedValue(new TypeError('Network internals')));
    await expect(api('/config')).rejects.toMatchObject({status:503,message:'Tidak dapat terhubung ke server backend.'});
  });
  it('mempertahankan status dan pesan validasi dari backend Laravel',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response(JSON.stringify({message:'Periksa tanggal.',details:['NOTICE_PERIOD']}),{status:422})));
    await expect(api('/leave-requests')).rejects.toMatchObject({status:422,message:'Periksa tanggal.',details:['NOTICE_PERIOD']});
  });
  it('menolak halaman HTML dan tidak menampilkannya sebagai pesan kesalahan',async()=>{
    vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response('<html>PRIVATE_SERVER_DETAIL</html>',{status:502})));
    await expect(api('/dashboard')).rejects.toMatchObject({status:502,message:'Respons server tidak dapat dibaca. Coba lagi.'});
  });
  it('menolak respons 200 berupa halaman HTML atau null sebelum merusak tampilan',async()=>{
    for(const body of ['<html>Login</html>','null']){
      vi.stubGlobal('fetch',vi.fn().mockResolvedValue(new Response(body,{status:200})));
      await expect(api('/dashboard')).rejects.toBeInstanceOf(ApiError);
    }
  });
  it('tetap mengirim kredensial, header klien, dan kunci idempotensi',async()=>{
    const fetch=vi.fn().mockResolvedValue(new Response(JSON.stringify({ok:true})));
    vi.stubGlobal('fetch',fetch);
    await expect(api('/drafts',{method:'POST',body:{reason:'Keluarga'},key:'request-once'})).resolves.toEqual({ok:true});
    expect(fetch).toHaveBeenCalledWith('/api/drafts',expect.objectContaining({credentials:'same-origin',method:'POST',headers:expect.objectContaining({'X-Cuti-Client':'web','Idempotency-Key':'request-once'})}));
  });
});
