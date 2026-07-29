import type { APIRoute } from 'astro';
const routes=['/','/services/','/cooperation/','/about/','/contact/','/articles'];
export const GET: APIRoute = () => {
  const lastmod=new Date().toISOString().slice(0,10);
  return new Response(`<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${routes.map((route)=>`<url><loc>https://lezhai.life${route}</loc><lastmod>${lastmod}</lastmod></url>`).join('')}</urlset>`,{headers:{'Content-Type':'application/xml'}});
};
