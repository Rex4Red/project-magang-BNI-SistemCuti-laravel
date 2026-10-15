export class ApiError extends Error {constructor(message:string,public status:number,public details?:any){super(message);}}
export async function api<T=any>(path:string,options:{method?:string;body?:unknown;key?:string}={}):Promise<T> {
  let response: Response;
  try {
    response=await fetch('/api'+path,{
      method:options.method??'GET',
      credentials:'same-origin',
      headers:{
        'Content-Type':'application/json',
        'X-Cuti-Client':'web',
        ...(options.key?{'Idempotency-Key':options.key}:{})
      },
      body:options.body===undefined?undefined:JSON.stringify(options.body)
    });
  } catch {
    throw new ApiError('Tidak dapat terhubung ke server backend.', 503);
  }
  let text: string;
  try {
    text = await response.text();
  } catch {
    throw new ApiError('Koneksi terputus saat menerima respons server.',503);
  }
  let data: any;
  try {
    data = JSON.parse(text);
  } catch {
    throw new ApiError('Respons server tidak dapat dibaca. Coba lagi.',response.ok?502:response.status);
  }
  if(!response.ok)throw new ApiError(typeof data?.message==='string'?data.message:'Permintaan gagal.',response.status,data?.details);
  if(data===null||typeof data!=='object')throw new ApiError('Respons server tidak dapat dibaca. Coba lagi.',502);
  return data;
}
export const action=(path:string,body:unknown,key=crypto.randomUUID())=>api(path,{method:'POST',body,key});
