(() => {
  const articleForm = document.querySelector('[data-article-form]');
  if (articleForm) {
    const editor = articleForm.querySelector('[data-editor]');
    const source = articleForm.querySelector('[data-editor-source]');
    articleForm.addEventListener('submit', () => { source.value = editor.innerHTML; });
    articleForm.querySelectorAll('[data-command]').forEach(button => button.addEventListener('click', () => {
      document.execCommand(button.dataset.command, false); editor.focus();
    }));
    articleForm.querySelectorAll('[data-block]').forEach(button => button.addEventListener('click', () => {
      document.execCommand('formatBlock', false, button.dataset.block); editor.focus();
    }));
    articleForm.querySelector('[data-link]')?.addEventListener('click', () => {
      const url = window.prompt('请输入链接地址（https:// 或站内 / 路径）');
      if (url) document.execCommand('createLink', false, url);
    });
    const imageInput = articleForm.querySelector('[data-image-input]');
    articleForm.querySelector('[data-image]')?.addEventListener('click', () => imageInput.click());
    const uploadEditorImage = async file => {
      if (!file || !file.type.startsWith('image/')) return;
      const data = new FormData();
      data.append('_csrf', articleForm.querySelector('[name="_csrf"]').value);
      data.append('image', file);
      const response = await fetch('/admin/articles/image', { method: 'POST', body: data });
      if (!response.ok) { window.alert('图片上传失败，请检查格式或大小。'); return; }
      const result = await response.json();
      editor.focus(); document.execCommand('insertImage', false, result.url);
    };
    imageInput?.addEventListener('change', async () => { await uploadEditorImage(imageInput.files?.[0]); imageInput.value = ''; });
    editor.addEventListener('paste', event => {
      const file = [...(event.clipboardData?.files || [])].find(item => item.type.startsWith('image/'));
      if (file) { event.preventDefault(); uploadEditorImage(file); }
    });
    editor.addEventListener('dragover', event => { event.preventDefault(); editor.classList.add('is-dragging'); });
    editor.addEventListener('dragleave', () => editor.classList.remove('is-dragging'));
    editor.addEventListener('drop', event => {
      editor.classList.remove('is-dragging');
      const file = [...(event.dataTransfer?.files || [])].find(item => item.type.startsWith('image/'));
      if (file) { event.preventDefault(); uploadEditorImage(file); }
    });
  }
  document.querySelectorAll('[data-pagination-heading]').forEach(slot => {
    const heading = [...document.querySelectorAll('.admin-section h2')].find(node => node.textContent.trim() === slot.dataset.paginationHeading);
    if (heading) heading.closest('.admin-section')?.append(slot);
  });
  const categorySection = [...document.querySelectorAll('.admin-section')].find(section => section.querySelector('h2')?.textContent.trim() === '分类');
  const tutorialSection = [...document.querySelectorAll('.admin-section')].find(section => section.querySelector('h2')?.textContent.trim() === '指纹锁教程');
  if (categorySection && tutorialSection) {
    const grid = document.createElement('div'); grid.className = 'admin-dual-grid';
    categorySection.before(grid); grid.append(categorySection, tutorialSection);
  }
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
    let favorites = readFavorites(); let filtering = false;
    let currentPage = Math.max(1, Number(new URL(location.href).searchParams.get('page') || 1));
    const grid = document.querySelector('[data-client-pagination]');
    const perPage = Number(grid?.dataset.clientPagination || 12);
    const cards = [...(grid?.querySelectorAll('.catalog-card') || [])];
    const pager = document.querySelector('[data-client-pagination-nav]');
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
      const eligible = cards.filter(card => !filtering || favorites.has(String(card.dataset.catalogId)));
      const pages = Math.max(1, Math.ceil(eligible.length / perPage)); currentPage = Math.min(currentPage, pages);
      cards.forEach(card => {
        const index = eligible.indexOf(card);
        const show = index >= (currentPage - 1) * perPage && index < currentPage * perPage;
        card.toggleAttribute('hidden', !show);
      });
      const empty = document.querySelector('[data-favorites-empty]');
      if (empty) empty.hidden = !filtering || eligible.length > 0;
      if (pager) {
        pager.innerHTML = pages <= 1 ? '' : Array.from({length: pages}, (_, index) => `<button type="button" class="${index + 1 === currentPage ? 'active' : ''}" data-page="${index + 1}" ${index + 1 === currentPage ? 'aria-current="page"' : ''}>${index + 1}</button>`).join('');
        pager.querySelectorAll('[data-page]').forEach(button => button.addEventListener('click', () => { currentPage = Number(button.dataset.page); const url=new URL(location.href);currentPage>1?url.searchParams.set('page',String(currentPage)):url.searchParams.delete('page');history.replaceState({},'',url);render();grid?.scrollIntoView({behavior:'smooth',block:'start'}); }));
      }
    };
    buttons.forEach(button => button.addEventListener('click', () => {
      const id = String(button.dataset.favoriteId);
      favorites.has(id) ? favorites.delete(id) : favorites.add(id);
      writeFavorites(favorites); render();
    }));
    filter?.addEventListener('click', () => { filtering = !filtering; currentPage = 1; render(); });
    window.addEventListener('storage', event => { if (event.key === favoriteKey) { favorites = readFavorites(); render(); } });
    render();
  }

  function initTutorialFavorites() {
    const buttons = [...document.querySelectorAll('[data-tutorial-favorite]')];
    if (!buttons.length) return;
    const key = 'lezhai_tutorial_favorites_v1';
    let saved;
    try { saved = new Set(JSON.parse(localStorage.getItem(key) || '[]').map(String)); } catch (_) { saved = new Set(); }
    const render = () => buttons.forEach(button => {
      const active = saved.has(String(button.dataset.tutorialFavorite));
      button.classList.toggle('saved', active); button.setAttribute('aria-pressed', active ? 'true' : 'false');
      button.innerHTML = `<span aria-hidden="true">${active ? '♥' : '♡'}</span> ${active ? '已收藏' : '收藏'}`;
    });
    buttons.forEach(button => button.addEventListener('click', () => {
      const id = String(button.dataset.tutorialFavorite); saved.has(id) ? saved.delete(id) : saved.add(id);
      try { localStorage.setItem(key, JSON.stringify([...saved])); } catch (_) {} render();
    })); render();
  }

  async function shareCatalog(button) {
    const url = new URL(button.dataset.shareUrl || location.href, location.origin).href;
    const title = button.dataset.shareTitle || '乐宅.Life 内容分享';
    if (navigator.share) {
      try { await navigator.share({ title, text: `查看乐宅.Life内容：${title}`, url }); return; }
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
    } catch (_) { window.prompt('复制下面的链接，发送给微信好友：', url); }
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
  initTutorialFavorites();
  document.querySelector('[data-close-reader]')?.addEventListener('click', () => closeReader());
  window.addEventListener('popstate', () => closeReader(true));
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && dialog?.open) closeReader(); });
  if (dialog?.dataset.autoReaderUrl) {
    openReader({ dataset: { readerUrl: dialog.dataset.autoReaderUrl, viewUrl: dialog.dataset.autoViewUrl, catalogTitle: dialog.dataset.autoTitle } });
  }
  const jobPanel = document.querySelector('[data-catalog-job]');
  if (jobPanel) {
    const message = jobPanel.querySelector('[data-job-message]');
    const progress = jobPanel.querySelector('[data-job-progress]');
    const error = jobPanel.querySelector('[data-job-error]');
    const download = jobPanel.querySelector('[data-job-download]');
    const labels = { queued: '等待后台处理', preparing: '正在准备', downloading: '正在下载高清页面', extracting: '正在生成本地图片', packaging: '正在打包 ZIP', completed: '生成完成', failed: '生成失败' };
    const poll = async () => {
      try {
        const response = await fetch(jobPanel.dataset.statusUrl, { headers: { Accept: 'application/json' } });
        if (!response.ok) return;
        const data = await response.json(); const job = data.job;
        if (!job) return;
        const current = Number(job.progress_current || 0); const total = Number(job.progress_total || 0);
        message.textContent = `${labels[job.phase] || job.phase}${total > 0 ? ` · ${current}/${total} 页` : ''}`;
        progress.hidden = !['pending', 'running'].includes(job.status); progress.max = Math.max(1, total); progress.value = current;
        error.hidden = job.status !== 'failed'; error.textContent = job.error || '';
        if (data.download_url) { download.href = data.download_url; download.hidden = false; }
        if (['pending', 'running'].includes(job.status)) window.setTimeout(poll, 1500);
      } catch (_) { window.setTimeout(poll, 3000); }
    };
    poll();
  }
})();
