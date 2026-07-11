(() => {
  const dialog = document.querySelector('#reader-dialog');
  const content = document.querySelector('#reader-content');
  const title = document.querySelector('#reader-title');
  let previousUrl = location.href;
  const favoriteKey = 'lezhai_favorites_v1';

  function readFavorites() {
    try {
      const value = JSON.parse(localStorage.getItem(favoriteKey) || '[]');
      return new Set(Array.isArray(value) ? value.map(String) : []);
    } catch (_) { return new Set(); }
  }

  function writeFavorites(favorites) {
    try { localStorage.setItem(favoriteKey, JSON.stringify([...favorites])); } catch (_) {}
  }

  function initFavorites() {
    const buttons = [...document.querySelectorAll('[data-favorite-id]')];
    const filter = document.querySelector('[data-favorites-filter]');
    if (!buttons.length && !filter) return;
    let favorites = readFavorites();
    let filtering = false;
    const render = () => {
      buttons.forEach(button => {
        const saved = favorites.has(String(button.dataset.favoriteId));
        button.classList.toggle('saved', saved);
        button.setAttribute('aria-pressed', saved ? 'true' : 'false');
        button.innerHTML = `<span aria-hidden="true">${saved ? '♥' : '♡'}</span> ${saved ? '已收藏' : '收藏'}`;
      });
      document.querySelectorAll('[data-favorites-count]').forEach(node => { node.textContent = favorites.size; });
      filter?.classList.toggle('active', filtering);
      filter?.setAttribute('aria-pressed', filtering ? 'true' : 'false');
      document.querySelector('.hot-section')?.toggleAttribute('hidden', filtering);
      let visible = 0;
      document.querySelectorAll('.catalog-grid .catalog-card').forEach(card => {
        const show = !filtering || favorites.has(String(card.dataset.catalogId));
        card.toggleAttribute('hidden', !show);
        if (show) visible++;
      });
      const empty = document.querySelector('[data-favorites-empty]');
      if (empty) empty.hidden = !filtering || visible > 0;
    };
    buttons.forEach(button => button.addEventListener('click', () => {
      const id = String(button.dataset.favoriteId);
      favorites.has(id) ? favorites.delete(id) : favorites.add(id);
      writeFavorites(favorites); render();
    }));
    filter?.addEventListener('click', () => { filtering = !filtering; render(); });
    window.addEventListener('storage', event => { if (event.key === favoriteKey) { favorites = readFavorites(); render(); } });
    render();
  }

  async function shareCatalog(button) {
    const url = new URL(button.dataset.shareUrl || location.href, location.origin).href;
    const title = button.dataset.shareTitle || '乐宅.Life 电子图册';
    if (navigator.share) {
      try { await navigator.share({ title, text: `查看这本电子图册：${title}`, url }); return; }
      catch (error) { if (error?.name === 'AbortError') return; }
    }
    try {
      if (navigator.clipboard?.writeText) await navigator.clipboard.writeText(url);
      else {
        const input = document.createElement('textarea'); input.value = url; input.style.position = 'fixed'; input.style.opacity = '0';
        document.body.appendChild(input); input.select(); document.execCommand('copy'); input.remove();
      }
      const original = button.innerHTML; button.innerHTML = '<span aria-hidden="true">✓</span> 已复制，发给微信好友';
      button.classList.add('copied'); setTimeout(() => { button.innerHTML = original; button.classList.remove('copied'); }, 2200);
    } catch (_) { window.prompt('复制下面的图册链接，发送给微信好友：', url); }
  }

  async function openReader(button) {
    if (!dialog || !content) return;
    previousUrl = location.href;
    title.textContent = button.dataset.catalogTitle || '电子图册';
    content.innerHTML = '<div class="reader-loading">正在载入图册…</div>';
    dialog.showModal();
    document.body.style.overflow = 'hidden';
    history.pushState({ reader: true }, '', `?catalog=${encodeURIComponent(button.dataset.catalogTitle || '')}`);
    fetch(button.dataset.viewUrl, { method: 'POST', credentials: 'same-origin' }).catch(() => {});
    try {
      const response = await fetch(button.dataset.readerUrl, { credentials: 'same-origin' });
      if (!response.ok) throw new Error('图册暂时无法载入');
      content.innerHTML = await response.text();
      initImageReader(content);
    } catch (error) {
      content.innerHTML = `<div class="reader-loading">${escapeHtml(error.message)}，请返回后重试。</div>`;
    }
  }

  function closeReader(fromHistory = false) {
    if (!dialog?.open) return;
    dialog.close();
    content.innerHTML = '';
    document.body.style.overflow = '';
    if (!fromHistory) history.replaceState({}, '', previousUrl);
  }

  function initImageReader(root) {
    const reader = root.querySelector('[data-image-reader]');
    if (!reader) return;
    const pages = JSON.parse(reader.querySelector('[data-pages]').textContent || '[]');
    const image = reader.querySelector('[data-page-image]');
    const current = reader.querySelector('[data-current]');
    let index = 0;
    const show = next => {
      index = Math.max(0, Math.min(pages.length - 1, next));
      image.src = pages[index]; image.alt = `图册第 ${index + 1} 页`; current.textContent = index + 1;
      reader.querySelector('[data-prev]').disabled = index === 0;
      reader.querySelector('[data-next]').disabled = index === pages.length - 1;
      [pages[index + 1], pages[index - 1]].filter(Boolean).forEach(src => { const preload = new Image(); preload.src = src; });
    };
    reader.querySelector('[data-prev]').addEventListener('click', () => show(index - 1));
    reader.querySelector('[data-next]').addEventListener('click', () => show(index + 1));
    let touchX = 0;
    reader.addEventListener('touchstart', event => { touchX = event.changedTouches[0].clientX; }, { passive: true });
    reader.addEventListener('touchend', event => { const diff = event.changedTouches[0].clientX - touchX; if (Math.abs(diff) > 45) show(index + (diff < 0 ? 1 : -1)); }, { passive: true });
    show(0);
  }

  function escapeHtml(value) { const div = document.createElement('div'); div.textContent = value; return div.innerHTML; }
  document.querySelectorAll('[data-reader-url]').forEach(button => button.addEventListener('click', () => openReader(button)));
  document.querySelectorAll('[data-share-url]').forEach(button => button.addEventListener('click', () => shareCatalog(button)));
  initFavorites();
  document.querySelector('[data-close-reader]')?.addEventListener('click', () => closeReader());
  window.addEventListener('popstate', () => closeReader(true));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && dialog?.open) closeReader(); });
  if (dialog?.dataset.autoReaderUrl) {
    openReader({ dataset: { readerUrl: dialog.dataset.autoReaderUrl, viewUrl: dialog.dataset.autoViewUrl, catalogTitle: dialog.dataset.autoTitle } });
  }
})();
