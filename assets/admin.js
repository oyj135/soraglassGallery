(() => {
  'use strict';
  document.querySelectorAll('[data-sgga]').forEach(root => {
    if (root.dataset.ready) return;
    root.dataset.ready = '1';
    const $ = selector => root.querySelector(selector);
    const el = (tag, text, cls) => { const n = document.createElement(tag); if (text != null) n.textContent = text; if (cls) n.className = cls; return n; };
    const states = {pending:'等待上传', uploading:'正在上传', uploaded:'已上传', failed:'上传失败', unknown:'结果待核查', removed:'已删除'};
    const notice = (text, tone = 'info', source = '') => { $('[data-alert]').textContent = text; $('[data-alert]').hidden = !text; $('[data-alert]').dataset.tone = tone; $('[data-alert]').dataset.source=source; };
    let albums = [], photos = [], page = 1, pages = 1, queue = [], busy = false, batchFinished = false, editing = null, refreshVersion = 0, fieldId = 0;
    // 相册筛选由左栏控制，右侧下拉改成排序；两者都不再共用一个 select。
    let filter = '', sortBy = 'captured';
    const mqNarrow = window.matchMedia('(max-width: 640px)');
    // 每页张数由插件配置给默认值（6–60），窄屏在未手动选择前压到 12 条——单列 24 条要滑 6 屏才够到分页。
    // 在列表上方下拉里选过一次就记住，此后完全按用户的选择走；服务端仍会再夹一次范围。
    const configuredPer = Math.min(60, Math.max(6, Number(root.dataset.perPage) || 24));
    const PER_KEY = 'sgga-per-page';
    let chosenPer = 0;
    // 记下当时生效的配置值：配置一改就作废手动选择，否则「改了配置却没反应」很难查。
    try { const saved = JSON.parse(localStorage.getItem(PER_KEY) || '0'); if (saved && saved.per >= 6 && saved.per <= 60 && saved.cfg === configuredPer) chosenPer = saved.per; } catch (e) {}
    const perPage = () => chosenPer || (mqNarrow.matches ? Math.min(12, configuredPer) : configuredPer);
    // 下拉是唯一显示当前值的地方：配置改成非档位数字时补一项，保证不会显示错行。
    function syncPer() {
      const select = $('[data-per-page]'); if (!select) return;
      const value = String(perPage());
      if (![...select.options].some(o => o.value === value)) {
        const option = el('option', `${value} 张/页`); option.value = value;
        select.append(option);
        [...select.options].sort((a, b) => Number(a.value) - Number(b.value)).forEach(o => select.append(o));
      }
      select.value = value;
    }
    const dialog = $('[data-editor]');
    const url = op => { const u = new URL(root.dataset.endpoint, location.href); u.searchParams.set('op', op); if(root.dataset.csrf)u.searchParams.set('_',root.dataset.csrf); return u; };
    const body = values => { const f = new FormData(); Object.entries(values).forEach(([k,v]) => f.append(k,v)); if(root.dataset.csrf)f.set('_',root.dataset.csrf); return f; };
    const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
    // 后台主题常给 button 加 ::before 图标或改写原生 date 控件，图标直接内联最稳。
    const CALENDAR_ICON = '<svg viewBox="0 0 16 16" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" aria-hidden="true" focusable="false"><rect x="2.2" y="3.4" width="11.6" height="10.4" rx="2"/><path d="M2.2 6.6h11.6M5.4 2.2v2.4M10.6 2.2v2.4"/></svg>';
    const size = bytes => Number(bytes) < 1048576 ? `${Math.ceil(Number(bytes)/1024)} KB` : `${(Number(bytes)/1048576).toFixed(1)} MB`;
    // 上传前转 WebP（质量 80）。放在浏览器端做：prepare 报的 bytes 与 send 发的
    // 就是转换后的同一个 Blob，PHP 侧「大小 + sha256」校验链条一行都不用改；
    // object_key 的扩展名取自文件名，也会自动变成 .webp。
    // 只转浏览器能安全解码的位图：gif 用 canvas 会丢帧变成静图，svg 无需转，
    // heic 等解不了 —— 一律原样上传，绝不为了统一格式把照片弄坏。
    // 返回 {file, note}：note 空 = 本来就不该转（gif/svg/heic/小图，静默跳过）；
    // note 有内容 = 本次转换结果或放弃原因，显示在队列行，便于核对是否真的生效。
    // 质量在插件配置里可调（40–95，默认 75）：75 是「看不出差别」的最低档，
    // 再低（70 以下）暗部会出色带、边缘发糊，相册这种大图展示看得出来。
    const WEBP_QUALITY = Math.min(95, Math.max(40, Number(root.dataset.webpQuality) || 75)) / 100;
    const convert = file => /^image\/(jpeg|png|webp|avif|bmp)$/.test(file.type) && file.size > 65536;
    function optimize(file) {
      if (!convert(file)) return Promise.resolve({ file, note: '' });
      return new Promise(resolve => {
        const img = new Image(); const src = URL.createObjectURL(file);
        const done = (result, note = '') => { URL.revokeObjectURL(src); resolve({ file: result, note }); };
        img.onload = () => {
          const w = img.naturalWidth, h = img.naturalHeight;
          // 单边 16384 是 Chrome 画布上限；面积再往上（>6000 万像素）直接放弃转换，
          // 免得为了转格式把标签页拖崩。放弃时走原图，上传照常。
          if (!w || !h || w * h > 6e7) { done(file, '保持原图 · 尺寸过大，浏览器内转不了'); return; }
          const canvas = document.createElement('canvas'); canvas.width = w; canvas.height = h;
          canvas.getContext('2d').drawImage(img, 0, 0);
          canvas.toBlob(blob => {
            // 老 Safari 不支持 webp 编码时 toBlob 会静默回退成 png：认 type 不认后缀。
            if (!blob) { done(file, '保持原图 · 浏览器未能生成图片'); return; }
            if (blob.type !== 'image/webp') { done(file, '保持原图 · 当前浏览器不支持 WebP 编码'); return; }
            if (blob.size >= file.size) { done(file, `保持原图 · 转 WebP 后没变小（${size(file.size)}）`); return; }
            done(new File([blob], file.name.replace(/\.[^.]+$/, '') + '.webp', { type: 'image/webp', lastModified: file.lastModified }), `已转 WebP · ${size(file.size)} → ${size(blob.size)}`);
          }, 'image/webp', WEBP_QUALITY);
        };
        // 解码失败（heic、损坏文件、超大面积）同样退回原图。
        img.onerror = () => done(file, '保持原图 · 浏览器无法解码该格式');
        img.src = src;
      });
    }
    // Only the gallery workspace breaks out of the narrow theme settings form.
    function layout() {
      if (root.closest('form')) {
        root.style.width = ''; root.style.marginLeft = '';
        const gutter = innerWidth < 640 ? 16 : 40;
        const left = root.getBoundingClientRect().left;
        root.style.width = `${document.documentElement.clientWidth - gutter * 2}px`;
        root.style.marginLeft = `${gutter - left}px`;
      }
      let ancestor = root.parentElement;
      while (ancestor) {
        const rgb = getComputedStyle(ancestor).backgroundColor.match(/[\d.]+/g)?.map(Number);
        if (rgb && rgb.length >= 3 && (rgb.length < 4 || rgb[3] > 0.8)) {
          root.dataset.tone = rgb[0]*.2126 + rgb[1]*.7152 + rgb[2]*.0722 < 128 ? 'dark' : 'light'; break;
        }
        ancestor = ancestor.parentElement;
      }
    }
    layout(); window.addEventListener('resize', layout);
    new MutationObserver(layout).observe(document.documentElement, {attributes:true, attributeFilter:['class','data-theme','style']});
    async function api(op, values, query = {}) {
      const u = url(op); Object.entries(query).forEach(([k,v]) => u.searchParams.set(k,v));
      const abort = new AbortController(); const timer = setTimeout(() => abort.abort(), op==='recover' ? 120000 : 20000);
      try {
        const res = await fetch(u, {method:values ? 'POST' : 'GET', body:values ? body(values) : undefined, credentials:'same-origin', signal:abort.signal});
        let data; try { data = await res.json(); } catch { throw new Error('服务器响应不完整，请刷新后重试。'); }
        if (!res.ok || !data.ok) throw new Error(data.error || '请求失败，请重试。');
        return data.data;
      } catch (e) { if (e.name === 'AbortError') { const error=new Error('请求等待超时，结果待核查。');error.uncertain=true;throw error; } throw e; }
      finally { clearTimeout(timer); }
    }
    function options(select, selected = '', all = false) {
      select.replaceChildren();
      if (all || !albums.length) { const o = el('option',all ? '全部相册' : '自动创建“光影收藏”'); o.value = ''; select.append(o); }
      albums.forEach(a => { const o = el('option',a.title); o.value = a.id; select.append(o); });
      if ([...select.options].some(o => o.value === selected)) select.value = selected;
    }
    function button(text, handler, cls) {
      const b = el('button',text,cls); b.type = 'button';
      b.addEventListener('click', async () => { try { const result=handler(); if(result?.then)b.disabled=true; await result; } catch(e) { notice(e.message, 'error'); } finally { b.disabled=false; } });
      return b;
    }
    function effectiveState(p) { return p.state === 'uploaded' ? 'uploaded' : p.task?.state || p.state; }
    async function refresh() {
      const version = ++refreshVersion;
      $('[data-photos]').setAttribute('aria-busy','true');
      try {
        let data = await api('list', null, {page, album:filter, per:perPage(), sort:sortBy});
        if (version !== refreshVersion) return;
        if (page > data.pages) { page = data.pages; data = await api('list', null, {page, album:filter, per:perPage(), sort:sortBy}); if (version !== refreshVersion) return; }
        albums = data.albums; photos = data.photos; pages = data.pages;
        options($('[data-upload-album]'), $('[data-upload-album]').value);
        $('[data-albums]').replaceChildren(...albums.map(a => {
          const row=el('div',null,'sgga-album-row');
          const b=button('',()=>{filter=a.id;$('[data-upload-album]').value=a.id;page=1;refresh();},'sgga-album');
          b.dataset.albumId=a.id;b.setAttribute('aria-current',filter===a.id?'true':'false');b.append(el('strong',a.title));if(+a.featured)b.append(el('small','精选'));
          const edit=button('⋯',()=>editAlbum(a),'sgga-album-edit');edit.setAttribute('aria-label',`编辑相册：${a.title}`);
          row.append(b,edit);return row;
        }));
        if (!albums.length) $('[data-albums]').append(el('p','直接上传即可开始收藏，也可以新建一本相册。'));
        $('[data-total]').textContent = `${data.total} 张`;
        $('[data-page]').textContent = `${page} / ${pages}`;
        $('[data-prev]').disabled = page <= 1; $('[data-next]').disabled = page >= pages;
        $('.sgga-pagination').hidden=pages<=1; syncPer();
        $('[data-photos]').replaceChildren(...photos.map(photoRow));
        if (!photos.length) $('[data-photos]').append(el('p',filter ? '这本相册还没有照片。' : '还没有照片，选择本机照片即可上传。','sgga-empty'));
        queue.forEach(item => { const p = photos.find(p => p.task?.id === item.task?.task); if (p) updateItem(item, {state:effectiveState(p),photoState:p.state,published:!!+p.published,error:p.task?.error}); });
        batchSummary($('[data-alert]').dataset.source==='batch');
      } catch(e) { if (version === refreshVersion) notice(e.message,'error'); }
      finally { if (version === refreshVersion) { $('[data-photos]').setAttribute('aria-busy','false'); root.dataset.settled='1';document.dispatchEvent(new Event('sgga:settled')); } }
    }
    function preview(p, cls, badge) {
      const box = el('div',null,cls);
      if (+p.preview && p.state === 'uploaded') {
        const img = el('img'); img.src = p.thumb || p.url; img.alt = p.caption || p.name; img.loading = 'lazy';
        let fallback=false;img.addEventListener('error',()=>{if(!fallback&&img.src!==p.url){fallback=true;img.src=p.url;}else box.replaceChildren(el('span','预览暂不可用，可打开原文件'));}); box.append(img);
      } else box.append(el('span',p.state === 'uploaded' ? '原文件收藏' : '等待照片上传'));
      // 状态做成图片左上角小徽章：卡片少一行文字，扫视时也不必读到操作行末尾
      if (badge) { const tag=el('span',badge.text,'sgga-photo-badge'); tag.dataset.state=badge.state; box.append(tag); }
      return box;
    }
    function details(p) {
      const d = el('details',null,'sgga-record'); d.append(el('summary','上传详情'));
      const list = el('dl'); const state = effectiveState(p);
      const values = {
        '文件名':p.name, '上传状态':`${states[state] || state}${state === 'uploaded' && +p.published ? '，已公开展示' : ''}`,
        '上传完成时间':+p.finished_at ? new Date(+p.finished_at*1000).toLocaleString('zh-CN') : '尚未完成或历史记录未记录', '记录创建时间':new Date(+p.created*1000).toLocaleString('zh-CN'), '文件大小':size(p.bytes),
        '云存储':`${p.storage.provider?.toUpperCase() || ''} / ${p.storage.bucket || ''}`, '存储路径':p.object_key
      };
      Object.entries(values).forEach(([k,v]) => list.append(el('dt',k),el('dd',v)));
      d.append(list); return d;
    }
    function photoRow(p) {
      const row = el('article',null,'sgga-photo'); row.dataset.photo = p.id;
      const info = el('div',null,'sgga-photo-info');
      const title = button(p.caption || p.name,()=>editPhoto(p),'sgga-photo-title'); title.title = p.caption || p.name; title.setAttribute('aria-label',`编辑照片：${p.caption || p.name}`);
      info.append(title);
      const state = effectiveState(p);
      info.append(el('p',`${albums.find(a=>a.id===p.album)?.title || '相册'}${p.captured ? ' · ' + p.captured : ''}`,'sgga-meta'));
      const foot = el('div',null,'sgga-photo-foot');
      foot.dataset.state = state;
      // 网格视图用图片角标；列表视图小图上放角标太抢眼，改用这一行文字（CSS 二选一显示）
      const badgeText = state === 'uploaded' ? (+p.published ? '已展示' : '已上传') : (states[state] || state);
      const stateTag = el('span',badgeText,'sgga-photo-status'); stateTag.dataset.state=state; foot.append(stateTag);
      foot.append(button('编辑',()=>editPhoto(p)));
      if (p.state === 'uploaded') { const a = el('a','原图'); a.href=p.url; a.target='_blank'; a.rel='noopener noreferrer'; foot.append(a); }
      const remove=button('删除',()=>askDelete(p),'sgga-photo-delete');remove.setAttribute('aria-label',`删除照片：${p.caption||p.name}`);foot.append(remove);
      info.append(foot);
      if (p.task?.error) info.append(el('p',p.task.error,'sgga-error'));
      if (p.task && state !== 'uploaded' && state !== 'uploading') {
        const actions = el('div',null,'sgga-retry');
        actions.append(button('核查云端', async () => {
          let result;
          try { result=await api('recover',{task:p.task.id}); }
          catch(e){if(!e.uncertain&&e.name!=='TypeError'&&!e.message.includes('响应不完整'))throw e;notice('核查请求响应未确认，正在读取服务器结果…');}
          for(let n=0;n<20&&(!result||result.state==='uploading');n++){if(n)await delay(1500);result=await api('status',null,{task:p.task.id});}
          notice(result?.state==='uploaded'?'已确认上传完成并展示。':result?.state==='uploading'?'服务器仍在核查，请稍后刷新记录。':result?.error||'结果待核查，请查看记录。');await refresh();
        }));
        actions.append(button('选择原照片重试',()=>{
          if (busy) throw new Error('请等待当前上传结束。');
          const picker=el('input'); picker.type='file';
          picker.addEventListener('change',async()=>{
            if (!picker.files[0]) return;
            queue=queue.filter(item=>!item.done);queue.push({file:picker.files[0],task:{task:p.task.id,photo:p.id}});picker.value='';batchFinished=false;queueRender();await uploadQueue();
          }); picker.click();
        })); info.append(actions);
      }
      info.append(details(p));
      row.append(preview(p,'sgga-photo-preview',{text:badgeText,state}),info); return row;
    }
    // 日期字段：整块可点、弹出自己绘制的日历。原生 input[type=date] 在中文环境下是
    // 「年/月/日」三段占位，点框不弹窗，还得在旁边摆「日历 / 清空」两个按钮才可用；
    // 这里换成只读文本 + 自定义日历：值仍是 YYYY-MM-DD（服务端与上传逻辑不用改），
    // 右侧补星期与「3 天前」，选完能一眼确认没选错。
    const WEEK = ['周日', '周一', '周二', '周三', '周四', '周五', '周六'];
    const isoOf = d => `${String(d.getFullYear()).padStart(4, '0')}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    function parseIso(iso) {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso || '')); if (!m) return null;
      const d = new Date(0); d.setFullYear(+m[1], +m[2] - 1, +m[3]); d.setHours(12, 0, 0, 0);
      return Number.isNaN(d.getTime()) || d.getMonth() !== +m[2] - 1 ? null : d;
    }
    function dateHint(iso) {
      const d = parseIso(iso); if (!d) return '';
      const today = new Date(); today.setHours(12, 0, 0, 0);
      const gap = Math.round((d - today) / 86400000);
      // gap 为「目标日期减今天」：负数在过去，正数在未来，符号写反会显示成「3 天后」。
      const rel = gap === 0 ? '今天' : gap === -1 ? '昨天' : gap === 1 ? '明天' : gap < -1 ? `${-gap} 天前` : `${gap} 天后`;
      return `${WEEK[d.getDay()]} · ${rel}`;
    }
    function bindDate(wrap) {
      const input = wrap.querySelector('input');
      const opener = wrap.querySelector('[data-date-open]');
      const box = wrap.querySelector('.sgga-date-box');
      const hint = wrap.querySelector('[data-date-hint]');
      const clear = wrap.querySelector('[data-date-clear]');
      const popup=el('div',null,'sgga-calendar');popup.setAttribute('popover','auto');popup.setAttribute('role','dialog');popup.setAttribute('aria-label','选择拍摄日期');popup.hidden=true;
      const uid=`sgga-calendar-${++fieldId}`;popup.id=uid;opener.setAttribute('aria-controls',uid);opener.setAttribute('aria-expanded','false');
      const nativePopover=typeof popup.showPopover==='function';
      const date=(year,month,day)=>{const d=new Date(0);d.setFullYear(year,month,day);d.setHours(12,0,0,0);return d;};
      let focused=new Date(),shownYear=focused.getFullYear(),shownMonth=focused.getMonth(),open=false;
      const header=el('div',null,'sgga-calendar-head'),year=el('input'),month=el('select');
      year.type='number';year.min=1;year.max=9999;year.setAttribute('aria-label','年份');month.setAttribute('aria-label','月份');
      for(let m=0;m<12;m++){const option=el('option',`${m+1}月`);option.value=m;month.append(option);}
      const days=el('div',null,'sgga-calendar-days');days.setAttribute('role','group');days.setAttribute('aria-label','选择日期，方向键移动');
      function sync(){const ok=!!parseIso(input.value);input.classList.toggle('is-empty',!ok);if(hint)hint.textContent=ok?dateHint(input.value):'';if(clear)clear.hidden=!ok;}
      function close(restore=false){if(!open)return;open=false;if(nativePopover)popup.hidePopover();else popup.hidden=true;opener.setAttribute('aria-expanded','false');if(restore)input.focus();}
      function choose(d){input.value=d?isoOf(d):'';sync();input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));close(true);}
      function render(){
        year.value=shownYear;month.value=shownMonth;days.replaceChildren();
        ['一','二','三','四','五','六','日'].forEach(day=>days.append(el('span',day)));
        const offset=(date(shownYear,shownMonth,1).getDay()+6)%7;
        for(let n=0;n<offset;n++)days.append(el('span',''));
        const count=date(shownYear,shownMonth+1,0).getDate();
        const today=isoOf(new Date());
        for(let n=1;n<=count;n++){
          const d=date(shownYear,shownMonth,n),value=isoOf(d),b=button(String(n),()=>choose(d));
          b.dataset.day=value;b.setAttribute('aria-label',`${shownYear}年${shownMonth+1}月${n}日`);b.setAttribute('aria-pressed',String(value===input.value));
          if(value===today)b.dataset.today='true';
          b.tabIndex=value===isoOf(focused)?0:-1;days.append(b);
        }
      }
      function moveMonth(step){const d=date(shownYear,shownMonth+step,1);if(d.getFullYear()<1||d.getFullYear()>9999)return;shownYear=d.getFullYear();shownMonth=d.getMonth();focused=date(shownYear,shownMonth,Math.min(focused.getDate(),date(shownYear,shownMonth+1,0).getDate()));render();}
      const prev=button('‹',()=>moveMonth(-1)),next=button('›',()=>moveMonth(1));prev.setAttribute('aria-label','上个月');next.setAttribute('aria-label','下个月');header.append(prev,year,month,next);
      year.addEventListener('change',()=>{if(!year.validity.valid || !year.value){year.value=shownYear;return;}shownYear=Number(year.value);focused=date(shownYear,shownMonth,1);render();});
      month.addEventListener('change',()=>{shownMonth=Number(month.value);focused=date(shownYear,shownMonth,1);render();});
      days.addEventListener('keydown',event=>{
        if(!event.target.dataset.day)return;
        if(event.key==='Enter'||event.key===' '){event.preventDefault();event.target.click();return;}
        const offsets={ArrowLeft:-1,ArrowRight:1,ArrowUp:-7,ArrowDown:7};
        if(!(event.key in offsets))return;event.preventDefault();
        const selected=event.target.dataset.day.split('-').map(Number);const d=date(selected[0],selected[1]-1,selected[2]+offsets[event.key]);
        if(d.getFullYear()<1||d.getFullYear()>9999)return;focused=d;shownYear=d.getFullYear();shownMonth=d.getMonth();render();days.querySelector(`[data-day="${isoOf(d)}"]`)?.focus();
      });
      const foot=el('div',null,'sgga-calendar-foot');
      const yesterday=()=>{const d=new Date();d.setHours(12,0,0,0);d.setDate(d.getDate()-1);return d;};
      foot.append(button('今天',()=>choose(new Date())),button('昨天',()=>choose(yesterday())),button('清空',()=>choose(null)));
      popup.append(header,days,foot);wrap.append(popup);
      popup.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close(true);}});
      popup.addEventListener('toggle',event=>{if(event.newState==='closed'){open=false;opener.setAttribute('aria-expanded','false');}});
      opener.addEventListener('click',()=>{
        if(open){close(true);return;}
        const current=parseIso(input.value);focused=current||new Date();shownYear=focused.getFullYear();shownMonth=focused.getMonth();render();
        popup.hidden=false;if(nativePopover)popup.showPopover();open=true;opener.setAttribute('aria-expanded','true');
        const rect=input.getBoundingClientRect();const width=Math.min(320,innerWidth-32);popup.style.width=`${width}px`;popup.style.left=`${Math.max(8,Math.min(rect.left,innerWidth-width-8))}px`;popup.style.top=`${Math.max(8,Math.min(rect.bottom+8,innerHeight-popup.offsetHeight-8))}px`;
        days.querySelector('[tabindex="0"]')?.focus();
      });
      // 整块字段都是入口：点框内空白或输入框都能唤起日历，只有清空按钮例外。
      box?.addEventListener('click',event=>{if(event.target.closest('button,input'))return;opener.click();});
      input.addEventListener('click',()=>{if(!open)opener.click();});
      input.addEventListener('keydown',event=>{if(event.key==='Enter'||event.key===' '||event.key==='ArrowDown'){event.preventDefault();opener.click();}});
      if(!nativePopover)document.addEventListener('click',event=>{if(open&&!wrap.contains(event.target))close();});
      clear?.addEventListener('click',event=>{
        event.stopPropagation();input.value='';sync();
        input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));input.focus();
      });
      sync();
    }
    bindDate($('.sgga-date'));
    function field(label, type, key, value = '') {
      const wrap = el('div',null,'sgga-field'); const labelEl=el('label',label);
      const input=el(type === 'textarea' ? 'textarea' : type === 'select' ? 'select' : 'input');
      input.id=`sgga-field-${++fieldId}`; input.dataset.key=key; input.setAttribute('aria-describedby','sgga-editor-error'); labelEl.htmlFor=input.id;
      if (type === 'select') options(input,value);
      else if (type === 'checkbox') { input.type='checkbox'; input.checked=!!Number(value); wrap.classList.add('sgga-check'); }
      else { if(type !== 'textarea') input.type = type === 'date' ? 'text' : type; input.value=value; }
      if (type === 'date') {
        input.readOnly=true; input.placeholder='选择拍摄日期';
        const date=el('div',null,'sgga-date'), box=el('div',null,'sgga-date-box');
        const open=el('button',null,'sgga-date-open'), hint=el('span',null,'sgga-date-hint'), clear=el('button','×','sgga-date-clear');
        open.type=clear.type='button'; open.dataset.dateOpen=''; clear.dataset.dateClear=''; hint.dataset.dateHint='';
        open.setAttribute('aria-haspopup','dialog'); open.setAttribute('aria-expanded','false'); open.setAttribute('aria-label','打开日历选择拍摄日期');
        clear.setAttribute('aria-label','清空拍摄日期'); hint.setAttribute('aria-hidden','true'); clear.hidden=true;
        open.innerHTML=CALENDAR_ICON; box.append(open,input,hint,clear); date.append(box); wrap.append(labelEl,date); bindDate(date);
      } else if (type === 'checkbox') wrap.append(input,labelEl);
      else wrap.append(labelEl,input);
      if (type === 'textarea') input.addEventListener('input',()=>{ input.style.height='auto'; input.style.height=`${Math.max(96,input.scrollHeight)}px`; });
      input.addEventListener('input',()=>{input.removeAttribute('aria-invalid');$('[data-editor-error]').textContent='';});
      $('[data-editor-fields]').append(wrap); return input;
    }
    function values() {
      const result={}; $('[data-editor-fields]').querySelectorAll('[data-key]').forEach(i=>result[i.dataset.key]=i.type==='checkbox'?(i.checked?1:0):i.value); return result;
    }
    function dirty() { return editing && JSON.stringify(values()) !== editing.original; }
    function openEditor(kind, record, title) {
      editing={kind,record,focus:document.activeElement}; $('[data-editor-fields]').replaceChildren(); $('[data-editor-preview]').replaceChildren(); $('[data-editor-preview]').hidden=true;
      $('[data-editor-error]').textContent=''; $('[data-discard]').hidden=true; $('#sgga-editor-title').textContent=title;
      // 相册也能删，新建时没有 id 所以不给
      $('[data-editor-delete]').hidden = !(kind === 'photo' || record.id);
      $('[data-editor-delete]').textContent = kind === 'photo' ? '删除这张照片' : '删除这本相册';
    }
    function showEditor() { editing.original=JSON.stringify(values()); dialog.showModal(); $('[data-editor-fields] input, [data-editor-fields] textarea, [data-editor-fields] select')?.focus(); }
    function editAlbum(a = {}) {
      openEditor('album',a,a.id ? '相册设置' : '新建相册');
      field('相册名称','text','title',a.title||'').required=true;
      field('相册主题 / 简介（选填）','textarea','description',a.description||'');
      field('排序（精选优先，其次数字小的在前）','number','sort',a.sort||0);
      field('设为精选相册','checkbox','featured',a.featured||0);
      dialog.classList.add('sgga-editor--album');$('[data-editor-fields]').append(el('p','相册默认公开。上传第一张照片后，即会出现在画廊；封面自动选择，也可在照片编辑中指定。','sgga-meta')); showEditor();
    }
    function editPhoto(p) {
      dialog.classList.remove('sgga-editor--album');
      openEditor('photo',p,'照片设置');
      const picture=$('[data-editor-preview]'); picture.hidden=false; picture.append(preview(p,'sgga-editor-image'),el('p',p.name,'sgga-meta'),details(p));
      field('照片说明（选填）','textarea','caption',p.caption);
      field('所属相册','select','album',p.album);
      field('拍摄日期（未知可留空）','date','captured',p.captured);
      if (+p.preview && p.state === 'uploaded') field('用作所属相册的封面','checkbox','setCover',0);
      showEditor();
    }
    function closeEditor(force = false) {
      if ($('[data-save]').disabled) return;
      if (!force && dirty()) { $('[data-discard]').hidden=false; $('[data-keep]').focus(); return; }
      dialog.close();
    }
    dialog.addEventListener('cancel',event=>{event.preventDefault();closeEditor();});
    dialog.addEventListener('close',()=>{editing?.focus?.focus();editing=null;});
    $('[data-close]').addEventListener('click',()=>closeEditor());
    $('[data-keep]').addEventListener('click',()=>{ $('[data-discard]').hidden=true; $('[data-close]').focus(); });
    $('[data-discard-close]').addEventListener('click',()=>closeEditor(true));
    // 删除：先确认再动手。云端对象删除失败时相册记录保持不变，可以直接重试；
    // 取消勾选则只移除记录，原图仍留在云端（以前的 Bucket 用当前凭据删不掉，只能这样）。
    const confirmDialog=$('[data-confirm]');
    let pendingDelete=null, deleting=false;
    function askDelete(p){
      pendingDelete={...p,kind:'photo'};
      confirmDialog.dataset.kind='photo';
      $('#sgga-confirm-title').textContent='删除照片'; $('[data-confirm-ok]').textContent='删除照片';
      $('[data-confirm-purge-wrap]').hidden=false; $('[data-confirm-preview]').hidden=false;
      const purge=$('[data-confirm-purge]');
      purge.checked=!!p.object_key; purge.disabled=!p.object_key;
      $('[data-confirm-text]').textContent=`将删除「${p.caption||p.name}」${p.captured?`（${p.captured} 拍摄）`:''}。`;
      $('[data-confirm-preview]').replaceChildren(preview(p,'sgga-confirm-image'));
      $('[data-confirm-error]').textContent='';
      $('[data-confirm-note]').textContent = p.object_key ? '关闭此选项只从相册移除记录，云端原图仍会保留。' : '这条记录没有云端文件，删除只会移除相册记录。';
      confirmDialog.showModal(); $('[data-confirm-cancel]').focus();
    }
    // 相册只允许删空的：确认框里不给「连照片一起删」，语义比照片删除简单得多。
    function askDeleteAlbum(a){
      pendingDelete={...a,kind:'album'};
      confirmDialog.dataset.kind='album';
      $('#sgga-confirm-title').textContent='删除相册'; $('[data-confirm-ok]').textContent='删除相册';
      $('[data-confirm-purge-wrap]').hidden=true; $('[data-confirm-preview]').hidden=true;
      $('[data-confirm-preview]').replaceChildren();
      $('[data-confirm-text]').textContent=`将删除相册「${a.title}」。`;
      $('[data-confirm-note]').textContent='相册里还有照片时无法删除；请先把照片删掉或移到其他相册。';
      $('[data-confirm-error]').textContent='';
      confirmDialog.showModal(); $('[data-confirm-cancel]').focus();
    }
    function closeConfirm(){ if(deleting)return; confirmDialog.close(); }
    confirmDialog.addEventListener('cancel',event=>{event.preventDefault();closeConfirm();});
    confirmDialog.addEventListener('close',()=>{pendingDelete=null;});
    confirmDialog.addEventListener('click',event=>{if(event.target===confirmDialog)closeConfirm();});
    $('[data-confirm-close]').addEventListener('click',()=>closeConfirm());
    $('[data-confirm-cancel]').addEventListener('click',()=>closeConfirm());
    $('[data-confirm-ok]').addEventListener('click',async()=>{
      if(!pendingDelete||deleting)return;
      const ok=$('[data-confirm-ok]'), purge=$('[data-confirm-purge]');
      deleting=true; ok.disabled=true; confirmDialog.setAttribute('aria-busy','true');
      try{
        const kind=pendingDelete.kind;
        const result=kind==='album'
          ? await api('album-delete',{id:pendingDelete.id})
          : await api('delete',{id:pendingDelete.id,purge:purge.checked?'1':''});
        // 删掉的正是当前筛选的相册时回全部照片，否则列表会停在空结果上
        if(kind==='album'&&filter===pendingDelete.id)filter='';
        confirmDialog.close();
        notice(kind==='album' ? '相册已删除。' : (result.warning || '照片已删除。'), kind==='album' || !result.warning ? 'success' : 'info');
        await refresh();
      }catch(e){
        $('[data-confirm-error]').textContent=e.message; $('[data-confirm-error]').focus();
      }finally{deleting=false;ok.disabled=false;confirmDialog.removeAttribute('aria-busy');}
    });
    $('[data-editor-delete]').addEventListener('click',()=>{
      if(!editing||$('[data-save]').disabled)return;
      const {kind,record}=editing; editing=null; $('[data-discard]').hidden=true; dialog.close();
      if(kind==='photo')askDelete(record);else askDeleteAlbum(record);
    });
    $('[data-save]').addEventListener('click',async()=>{
      const b=$('[data-save]'); const inputValues=values();
      const invalid=[...$('[data-editor-fields]').querySelectorAll('[data-key]')].find(i=>!i.validity.valid);
      if (invalid) { invalid.setAttribute('aria-invalid','true'); $('[data-editor-error]').textContent=invalid.dataset.key==='captured'?'请填写有效的拍摄日期，或清空日期。':'请填写相册名称。';invalid.focus();return; }
      b.disabled=true; dialog.setAttribute('aria-busy','true');
      const payload={id:editing.record.id||'',revision:editing.record.revision||0,...inputValues};
      const kind=editing.kind;
      try {
        const result=await api(kind,payload);
        if (kind==='album' && !editing.record.id) { albums.push(result); options($('[data-upload-album]'), result.id); }
        dialog.close(); notice('设置已保存。'); await refresh();
        const id=kind==='album' ? result.id : payload.id;
        $(kind==='album' ? `[data-album-id="${id}"]` : `[data-photo="${id}"] .sgga-photo-title`)?.focus();
      } catch(e) {
        $('[data-editor-error]').textContent=e.message; $('[data-editor-error]').focus();
        if (e.message.includes('拍摄日期')) $('[data-key=captured]')?.setAttribute('aria-invalid','true');
      } finally { b.disabled=false; dialog.removeAttribute('aria-busy'); }
    });
    $('[data-new-album]').addEventListener('click',()=>editAlbum());
    $('[data-all-photos]').addEventListener('click',()=>{filter='';page=1;refresh();});
    function updateItem(item, result) {
      if (!item.status) return;
      item.state=result.state; item.error=result.error || '';
      item.done=result.state==='uploaded' && result.photoState==='uploaded' && !!result.published;
      item.album=result.album||item.album;
      item.status.textContent=item.done ? '上传完成 · 已展示' : result.state==='uploading' ? (result.phase==='writing'?'云端写入中…':result.phase==='verifying'?'云端校验中…':'正在核查云端…') : states[result.state] || '结果待核查';
      if(result.state==='uploading')item.progress.removeAttribute('value');
      item.row.dataset.state=result.state; item.progress.hidden=item.done;
      if (item.done) item.progress.value=100;
      // item.error 优先；没有错误时保留格式转换结果，上传完成后仍能看到「已转 WebP · x → y」，
      // 否则这条唯一能证明转换生效的线索会在状态刷新的瞬间被清掉。
      item.message.textContent=item.error||item.convertNote||'';
    }
    function queueRender() {
      $('[data-queue]').hidden=false;
      $('[data-queue]').replaceChildren(...queue.map(item=>{
        const r=el('div',null,'sgga-queue-row'); item.row=r;
        const name=el('span',item.file.name); name.title=item.file.name;
        item.status=el('span','等待上传'); item.progress=el('progress'); item.progress.max=100; item.progress.value=0; item.progress.setAttribute('aria-label',`${item.file.name} 上传进度`);
        item.message=el('p','', 'sgga-error'); const remove=button('移出队列',()=>{queue=queue.filter(q=>q!==item);queueRender();},'sgga-queue-remove');remove.dataset.queueRemove='';remove.setAttribute('aria-label',`从待上传队列移除：${item.file.name}`);remove.disabled=busy;
        r.append(name,item.status,remove,item.progress,item.message);if(item.state)updateItem(item,{state:item.state,photoState:item.done?'uploaded':item.state,published:item.done,error:item.error,album:item.album});return r;
      }));
      $('[data-selection]').textContent=queue.length ? `已选择 ${queue.length} 张，共 ${size(queue.reduce((n,i)=>n+i.file.size,0))}` : '也可以将照片拖到这里';
      $('[data-upload]').disabled=!queue.length || busy;
    }
    function batchSummary(showNotice = false) {
      if (!batchFinished || busy || !queue.length) return;
      const done=queue.filter(item=>item.done).length, all=done===queue.length;
      $('[data-queue]').hidden=all;
      $('[data-selection]').textContent=`本次上传 ${queue.length} 张，${done} 张已展示`;
      if (showNotice) notice(all ? `本次 ${done} 张照片已上传并展示。` : `本次上传 ${queue.length} 张，${done} 张已展示；其余照片请查看具体状态。`,all?'success':'error','batch');
    }
    function selectFiles(files) { if (busy || !files.length) return;queue=queue.filter(item=>!item.done);const key=f=>`${f.name}\u0000${f.size}\u0000${f.lastModified}`;const seen=new Set(queue.map(i=>key(i.file)));let skipped=0;for(const file of files){if(seen.has(key(file))){skipped++;continue;}seen.add(key(file));queue.push({file});}batchFinished=false;queueRender();notice(skipped?`已略过 ${skipped} 张重复照片。`:''); }
    $('[data-files]').addEventListener('change',()=>{selectFiles([...$('[data-files]').files]);$('[data-files]').value='';});
    $('[data-pick]').addEventListener('click',()=>{if(!busy)$('[data-files]').click();});
    $('[data-drop]').addEventListener('dragover',e=>{e.preventDefault();if(!busy)$('[data-drop]').classList.add('is-dragging');});
    $('[data-drop]').addEventListener('dragleave',e=>{if(!$('[data-drop]').contains(e.relatedTarget))$('[data-drop]').classList.remove('is-dragging');});
    $('[data-drop]').addEventListener('drop',e=>{e.preventDefault();$('[data-drop]').classList.remove('is-dragging');selectFiles(e.dataTransfer.files);});
    function send(task,file,progress) {
      return new Promise((resolve,reject)=>{
        const xhr=new XMLHttpRequest(); xhr.open('POST',url('upload')); xhr.withCredentials=true;
        xhr.timeout=(Math.max(30,Math.min(3600,Number(root.dataset.uploadTimeout)||300))+60)*1000;
        xhr.upload.onprogress=e=>progress(e.lengthComputable?Math.round(e.loaded/e.total*100):0);
        xhr.onload=()=>{
          let data; try { data=JSON.parse(xhr.responseText); } catch { return reject(new Error('上传响应不完整')); }
          xhr.status>=200 && xhr.status<300 && data.ok ? resolve(data.data) : reject(new Error(data.error||'上传失败'));
        };
        xhr.onerror=()=>reject(new Error('网络连接中断')); xhr.onabort=()=>reject(new Error('上传中断'));
        xhr.ontimeout=()=>reject(new Error('上传请求超时，正在核对保存结果'));
        const f=body({task}); f.append('photo',file); xhr.send(f);
      });
    }
    async function reconcile(item, error) {
      item.status.textContent='正在确认上传结果…';
      try {
        let result;
        for(let n=0;n<3;n++) {
          result=await api('status',null,{task:item.task.task});
          if(result.state!=='uploading')break;
          await delay(750);
        }
        updateItem(item,result);
        if(!item.done) item.message.textContent=result.error || `${error.message}。请在照片记录中核查云端或重试。`;
      } catch { updateItem(item,{state:'unknown'});item.message.textContent=`${error.message}，暂时无法确认结果。请刷新记录后核查云端。`; }
    }
    async function uploadQueue() {
      if(busy || !queue.length)return;
      const date=$('[data-upload-date]'), album=$('[data-upload-album]');
      if(!date || !album){notice('上传界面未完整加载，请刷新页面后重试。','error');return;}
      if(!date.validity.valid){notice('请填写有效的拍摄日期，或清空日期。','error');date.focus();return;}
      const metadata={album:album.value,captured:date.value};
      const previousPage=page;
      const controls=[...['[data-upload]','[data-files]','[data-pick]','[data-upload-fields]','[data-sort]','[data-prev]','[data-next]','[data-all-photos]'].map($).filter(Boolean),...root.querySelectorAll('[data-queue-remove],.sgga-album')];const disabled=controls.map(c=>c.disabled);
      busy=true;
      try {
      controls.forEach(control=>control.disabled=true); notice('正在准备上传…');
      // Resolve the automatic album once before concurrent prepare requests.
      if(!metadata.album){const created=await api('album',{title:'光影收藏'});metadata.album=created.id;}
      const pending=queue.filter(item=>!item.done);let cursor=0;
      async function worker(){while(cursor<pending.length){const item=pending[cursor++];item.album=metadata.album;
        item.progress.hidden=false;
        let polling=false,active=true;
        const poll=setInterval(async()=>{if(polling||!item.task)return;polling=true;try{const status=await api('status',null,{task:item.task.task});if(active&&!item.done&&status.state==='uploading')updateItem(item,status);}catch{}finally{polling=false;}},2000);
        try {
          item.status.textContent='准备上传…';
          if(!item.task){
            // 只转一次：失败重试时不再对同一个文件重复解码。
            if(!item.prepared){
              item.prepared=true;
              item.status.textContent='正在转换为 WebP…';
              const {file:next,note}=await optimize(item.file);
              item.file=next; item.convertNote=note; item.message.textContent=note;
            }
            item.task=await api('prepare',{...metadata,name:item.file.name,bytes:item.file.size});
          }
          const result=await send(item.task.task,item.file,n=>{if(n>=100)item.progress.removeAttribute('value');else item.progress.value=n;item.status.textContent=n>=100?'云端写入 / 校验中…':`发送照片 ${n}%`;});
          updateItem(item,result);
          if(!item.done)await reconcile(item,new Error('正在等待云端确认'));
        } catch(e) {
          if(item.task)await reconcile(item,e);
          else updateItem(item,{state:'failed',error:e.message});
        }finally{active=false;clearInterval(poll);}
      }}await Promise.all(Array.from({length:Math.min(2,pending.length)},worker));
      } catch(e) {
        queue.filter(item=>!item.done).forEach(item=>updateItem(item,{state:'failed',error:e.message || '上传未启动，请重新选择照片后重试。'}));
        notice(e.message || '上传未启动，请重试。','error');
      } finally {
        busy=false;controls.forEach((control,index)=>control.disabled=disabled[index]);
      }
      $('[data-files]').value='';
      batchFinished=true;batchSummary(true);
      $('[data-upload]').disabled=true;
      const outside=!!filter&&queue.some(item=>item.done&&item.album!==filter);
      page=outside?1:previousPage; if(outside)filter='';await refresh();
      if(outside){const item=queue.find(item=>item.done&&item.album!==filter);const photo=root.querySelector(`[data-photo="${item?.task?.photo}"]`);photo?.scrollIntoView({block:'center',behavior:'smooth'});photo?.classList.add('sgga-photo--new');notice('新照片属于其他相册，已切换到全部照片并定位。');}
    }
    $('[data-upload]').addEventListener('click',uploadQueue);
    $('[data-refresh]').addEventListener('click',()=>{notice('');refresh();});
    $('[data-sort]').addEventListener('change',()=>{sortBy=$('[data-sort]').value;page=1;refresh();});
    // 每页张数改了，页码含义就变了，必须回第一页；非法值直接回显当前生效值。
    $('[data-per-page]').addEventListener('change',event=>{
      const value=Number(event.target.value);
      if(!(value>=6&&value<=60)){syncPer();return;}
      chosenPer=value;try{localStorage.setItem(PER_KEY,JSON.stringify({per:value,cfg:configuredPer}));}catch(e){}
      page=1;refresh();
    });
    $('[data-prev]').addEventListener('click',()=>{if(page>1){page--;refresh();}});
    $('[data-next]').addEventListener('click',()=>{if(page<pages){page++;refresh();}});
    // 视图切换只改容器上的 data-view，卡片 DOM 不重建，因此不触发任何取数。
    const viewKey='sgga-view', photosEl=$('[data-photos]');
    const applyView=view=>{photosEl.dataset.view=view;root.querySelectorAll('[data-view]').forEach(b=>b.setAttribute('aria-pressed',String(b.dataset.view===view)));};
    let view='grid'; try{view=localStorage.getItem(viewKey)==='list'?'list':'grid';}catch(e){}
    applyView(view);
    root.querySelectorAll('[data-view]').forEach(b=>b.addEventListener('click',()=>{view=b.dataset.view;applyView(view);try{localStorage.setItem(viewKey,view);}catch(e){}}));
    // 跨过断点后每页条数变了，页码含义也变了，回第一页重取；手动选过张数就不再自动改动。
    const onNarrow=()=>{syncPer();if(chosenPer)return;page=1;refresh();};
    if(mqNarrow.addEventListener)mqNarrow.addEventListener('change',onNarrow);else mqNarrow.addListener(onNarrow);
    // Suppress only accidental outer-form submission, preserving date/select and IME keys.
    root.addEventListener('keydown',event=>{if(event.key==='Enter' && !event.isComposing && event.target.matches('input[type=text],input[type=number]'))event.preventDefault();});
    root.closest('form')?.addEventListener('submit',event=>{if(root.contains(document.activeElement))event.preventDefault();});
    window.addEventListener('beforeunload',event=>{if(busy || (dialog.open && dirty())){event.preventDefault();event.returnValue='';}});
    refresh();
  });
})();
