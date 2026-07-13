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
    const labels = { queued: '等待后台处理', preparing: '正在准备', rendering: '正在还原高清页面', downloading: '正在下载高清页面', extracting: '正在生成本地图片', packaging: '正在打包 ZIP', completed: '生成完成', failed: '生成失败' };
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

  const mediaPanel = document.querySelector('[data-tutorial-media]');
  if (mediaPanel) {
    const uploadForm = mediaPanel.querySelector('[data-media-upload]');
    const orderForm = mediaPanel.querySelector('[data-media-order]');
    const list = mediaPanel.querySelector('[data-media-list]');
    const source = uploadForm.querySelector('[name="source_type"]');
    const fileLabel = uploadForm.querySelector('[data-media-file]');
    const urlLabel = uploadForm.querySelector('[data-media-url]');
    const fileInput = uploadForm.querySelector('[name="media"]');
    const submit = uploadForm.querySelector('[data-media-submit]');
    const progressBox = uploadForm.querySelector('[data-upload-progress]');
    const progress = progressBox.querySelector('progress');
    const progressText = progressBox.querySelector('span');
    const error = uploadForm.querySelector('[data-media-error]');
    const syncSource = () => {
      const uploading = source.value === 'upload';
      fileLabel.hidden = !uploading; urlLabel.hidden = uploading;
      submit.hidden = uploading && !fileInput.files?.length;
      submit.textContent = uploading ? '上传附件' : '添加外链附件';
    };
    source.addEventListener('change', syncSource); fileInput.addEventListener('change', syncSource); syncSource();
    const addRow = media => {
      const row = document.createElement('tr'); row.dataset.mediaId = media.id;
      const titleCell = document.createElement('td'); titleCell.textContent = media.title || '未命名附件';
      const typeCell = document.createElement('td'); typeCell.textContent = `${media.media_type === 'video' ? '视频' : '文档'} · ${media.source_type === 'upload' ? '上传' : '外链'}`;
      const orderCell = document.createElement('td'); const order = document.createElement('input'); order.className = 'media-order-input'; order.type = 'number'; order.name = `orders[${media.id}]`; order.value = media.sort_order || 0; order.setAttribute('aria-label', '附件排序'); orderCell.append(order);
      const actionCell = document.createElement('td'); actionCell.className = 'actions'; const remove = document.createElement('button'); remove.type = 'button'; remove.dataset.mediaDeleteUrl = media.delete_url; remove.textContent = '删除'; actionCell.append(remove);
      row.append(titleCell, typeCell, orderCell, actionCell); list.append(row);
    };
    uploadForm.addEventListener('submit', event => {
      event.preventDefault(); error.hidden = true; submit.disabled = true; progressBox.hidden = false; progress.value = 0; progressText.textContent = '0%';
      const request = new XMLHttpRequest(); request.open('POST', uploadForm.action); request.setRequestHeader('Accept', 'application/json');
      request.upload.addEventListener('progress', upload => { if (!upload.lengthComputable) return; const percent = Math.round(upload.loaded / upload.total * 100); progress.value = percent; progressText.textContent = `${percent}%`; });
      request.addEventListener('load', () => {
        let result = {}; try { result = JSON.parse(request.responseText || '{}'); } catch (_) {}
        if (request.status < 200 || request.status >= 300) { error.textContent = result.error || '附件上传失败。'; error.hidden = false; }
        else { result.media.delete_url = result.delete_url; addRow(result.media); uploadForm.reset(); progress.value = 100; progressText.textContent = '已完成'; }
        submit.disabled = false; syncSource();
      });
      request.addEventListener('error', () => { error.textContent = '网络中断，请重试。'; error.hidden = false; submit.disabled = false; });
      request.send(new FormData(uploadForm));
    });
    orderForm.addEventListener('submit', async event => {
      event.preventDefault(); const message = orderForm.querySelector('[data-order-message]');
      try { const response = await fetch(orderForm.action, { method: 'POST', body: new FormData(orderForm), headers: { Accept: 'application/json' } }); const result = await response.json(); if (!response.ok) throw new Error(result.error || '保存失败。'); [...list.querySelectorAll('tr')].sort((first, second) => Number(second.querySelector('.media-order-input').value) - Number(first.querySelector('.media-order-input').value)).forEach(row => list.append(row)); message.textContent = '排序已保存。'; }
      catch (failure) { message.textContent = failure.message; }
    });
    list.addEventListener('click', async event => {
      const button = event.target.closest('[data-media-delete-url]'); if (!button || !confirm('确定删除这个附件吗？')) return;
      const data = new FormData(); data.append('_csrf', orderForm.querySelector('[name="_csrf"]').value); button.disabled = true;
      try { const response = await fetch(button.dataset.mediaDeleteUrl, { method: 'POST', body: data, headers: { Accept: 'application/json' } }); if (!response.ok) throw new Error('删除失败。'); button.closest('tr').remove(); }
      catch (failure) { window.alert(failure.message); button.disabled = false; }
    });
  }

  const parseForm = document.querySelector('[data-catalog-parse-form]');
  if (parseForm) {
    const button = parseForm.querySelector('[data-parse-submit]'); const save = parseForm.querySelector('[data-catalog-save]'); const panel = parseForm.querySelector('[data-parse-panel]'); const message = parseForm.querySelector('[data-parse-message]'); const error = parseForm.querySelector('[data-parse-error]'); const preview = parseForm.querySelector('[data-parse-preview]'); const jobInput = parseForm.querySelector('[name="parse_job_id"]');
    const labels = { queued: '等待后台处理', preparing: '正在识别来源', parsing: '正在解析页面清单', caching_cover: '正在缓存封面', completed: '解析完成', failed: '解析失败' };
    const poll = async (id, expectedUrl) => {
      try {
        const response = await fetch(`${parseForm.dataset.statusBase}${id}`, { headers: { Accept: 'application/json' } }); const data = await response.json(); if (!response.ok) throw new Error(data.error || '无法读取解析进度。');
        if (parseForm.elements.source_url.value !== expectedUrl) { button.disabled = false; return; }
        message.textContent = labels[data.job.phase] || data.job.phase;
        if (data.job.status === 'failed') { error.textContent = data.job.error || '解析失败。'; error.hidden = false; button.disabled = false; button.textContent = '重新解析'; return; }
        if (data.job.status !== 'completed') { window.setTimeout(() => poll(id, expectedUrl), 1200); return; }
        const result = data.result; jobInput.value = id; error.hidden = true; panel.querySelector('progress').hidden = true; preview.hidden = false; preview.querySelector('img').src = result.cover_url; preview.querySelector('[data-parse-pages]').textContent = `解析成功 · ${result.pages.length} 页`; preview.querySelector('[data-parse-title]').textContent = result.title; preview.querySelector('[data-parse-description]').textContent = result.description || ''; preview.querySelector('[data-parse-source]').textContent = ({ yunzhan365: '云展网', goootu: 'goootu', flbook: 'FLBOOK' })[result.source_type] || result.source_type;
        if (!parseForm.elements.name.value) parseForm.elements.name.value = result.title || ''; if (!parseForm.elements.description.value) parseForm.elements.description.value = result.description || '';
        save.hidden = false; button.disabled = false; button.textContent = '重新解析';
      } catch (failure) { error.textContent = failure.message; error.hidden = false; button.disabled = false; }
    };
    button.addEventListener('click', async () => {
      const sourceUrl = parseForm.elements.source_url; if (!sourceUrl.reportValidity()) return;
      const expectedUrl = sourceUrl.value;
      button.disabled = true; save.hidden = true; preview.hidden = true; panel.hidden = false; panel.querySelector('progress').hidden = false; message.textContent = '正在加入后台队列'; error.hidden = true; jobInput.value = '';
      const data = new FormData(); data.append('_csrf', parseForm.elements._csrf.value); data.append('source_url', expectedUrl);
      try { const response = await fetch(parseForm.dataset.parseUrl, { method: 'POST', body: data, headers: { Accept: 'application/json' } }); const result = await response.json(); if (!response.ok) throw new Error(result.error || '无法创建解析任务。'); poll(result.job_id, expectedUrl); }
      catch (failure) { error.textContent = failure.message; error.hidden = false; button.disabled = false; }
    });
    parseForm.elements.source_url.addEventListener('input', () => { jobInput.value = ''; save.hidden = true; preview.hidden = true; });
  }
})();
