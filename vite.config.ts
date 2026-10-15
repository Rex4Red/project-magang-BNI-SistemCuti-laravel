import {defineConfig} from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins:[react()],
  base: process.env.NODE_ENV==='production'?'/build/':'/',
  publicDir:false,
  build:{outDir:'public/build',emptyOutDir:true},
  server:{proxy:{'/api':process.env.API_TARGET??'http://127.0.0.1:3004','/images':process.env.API_TARGET??'http://127.0.0.1:3004'}},
});
