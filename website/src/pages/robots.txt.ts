import type { APIRoute } from 'astro';
export const GET: APIRoute = () => {
  const production = import.meta.env.PUBLIC_SITE_ENV === 'production';
  const body = production
    ? 'User-agent: OAI-SearchBot\nAllow: /\n\nUser-agent: GPTBot\nDisallow: /\n\nUser-agent: *\nAllow: /\nDisallow: /admin\nSitemap: https://lezhai.life/sitemap.xml\n'
    : 'User-agent: *\nDisallow: /\n';
  return new Response(body,{headers:{'Content-Type':'text/plain; charset=utf-8'}});
};
