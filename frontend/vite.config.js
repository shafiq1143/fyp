import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Dev proxy: browser calls /cms-api → PHP API (same origin as Vite for cookies).
// Default: PHP built-in server (run: cd backend && php -S 127.0.0.1:8000)
// Or use Apache: set target to http://127.0.0.1 and rewrite to '/CMS/backend/index.php' + …
// Use VITE_API_BASE=/cms-api in .env.development when using `npm run dev`.
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/cms-api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
        rewrite: (path) => '/index.php' + path.replace(/^\/cms-api/, ''),
        secure: false, // In case of local dev issues
      },
    },
  },
});
