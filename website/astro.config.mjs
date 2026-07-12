import { defineConfig } from 'astro/config';
import react from '@astrojs/react';

export default defineConfig({
  site: 'https://lezhai.life',
  output: 'static',
  outDir: '../storage/website-dist',
  integrations: [react()],
  build: { format: 'directory' },
});
