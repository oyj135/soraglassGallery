(() => {
  'use strict';
  document.querySelectorAll('[data-sgga]').forEach(root => {
    if (root.dataset.ready) return;
    root.dataset.ready = '1';
    const $ = selector => root.querySelector(selector);
    const el = (tag, text, cls) => { const n = document.createElement(tag); if (text != null) n.textContent = text; if (cls) n.className = cls; return n; };
    const states = {pending:'等待上传', uploading:'正在上传', uploaded:'已上传', failed:'上传失败', unknown:'结果待核查'};
    const notice = (text, tone = 'info', source = '') => { $('[data-alert]').textContent = text; $('[data-alert]').hidden = !text; $('[data-alert]').dataset.tone = tone; $('[data-alert]').dataset.source=source; };
    let albums = [], photos = [], page = 1, pages = 1, queue = [], busy = false, batchFinished = false, editing = null, refreshVersion = 0, fieldId = 0;
    const dialog = $('[data-editor]');
    const url = op => { const u = new URL(root.dataset.endpoint, location.href); u.searchParams.set('op', op); return u; };
    const body = values => { const f = new FormData(); Object.entries(values).forEach(([k,v]) => f.append(k,v)); return f; };
    const delay = ms => new Promise(resolve => setTimeout(resolve, ms));
    const size = bytes => Number(bytes) < 1048576 ? `${Math.ceil(Number(bytes)/1024)} KB` : `${(Number(bytes)/1048576).toFixed(1)} MB`;
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
      const abort = new AbortController(); const timer = setTimeout(() => abort.abort(), 20000);
      try {
        const res = await fetch(u, {method:values ? 'POST' : 'GET', body:values ? body(values) : undefined, credentials:'same-origin', signal:abort.signal});
        let data; try { data = await res.json(); } catch { throw new Error('服务器响应不完整，请刷新后重试。'); }
        if (!res.ok || !data.ok) throw new Error(data.error || '请求失败，请重试。');
        return data.data;
      } catch (e) { if (e.name === 'AbortError') throw new Error('请求超时，请刷新后检查记录。'); throw e; }
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
      const filter = $('[data-filter]').value;
      $('[data-photos]').setAttribute('aria-busy','true');
      try {
        let data = await api('list', null, {page, album:filter});
        if (version !== refreshVersion) return;
        if (page > data.pages) { page = data.pages; data = await api('list', null, {page, album:filter}); if (version !== refreshVersion) return; }
        albums = data.albums; photos = data.photos; pages = data.pages;
        options($('[data-upload-album]'), $('[data-upload-album]').value);
        options($('[data-filter]'), filter, true);
        $('[data-albums]').replaceChildren(...albums.map(a => {
          const row=el('div',null,'sgga-album-row');
          const b=button('',()=>{$('[data-filter]').value=a.id;page=1;refresh();},'sgga-album');
          b.dataset.albumId=a.id;b.setAttribute('aria-current',filter===a.id?'true':'false');b.append(el('strong',a.title));if(+a.featured)b.append(el('small','精选'));
          const edit=button('⋯',()=>editAlbum(a),'sgga-album-edit');edit.setAttribute('aria-label',`编辑相册：${a.title}`);
          row.append(b,edit);return row;
        }));
        if (!albums.length) $('[data-albums]').append(el('p','直接上传即可开始收藏，也可以新建一本相册。'));
        $('[data-total]').textContent = `${data.total} 张`;
        $('[data-page]').textContent = `${page} / ${pages}`;
        $('[data-prev]').disabled = page <= 1; $('[data-next]').disabled = page >= pages;
        $('.sgga-pagination').hidden=pages<=1;
        $('[data-photos]').replaceChildren(...photos.map(photoRow));
        if (!photos.length) $('[data-photos]').append(el('p',filter ? '这本相册还没有照片。' : '还没有照片，选择本机照片即可上传。','sgga-empty'));
        queue.forEach(item => { const p = photos.find(p => p.task?.id === item.task?.task); if (p) updateItem(item, {state:effectiveState(p),photoState:p.state,published:!!+p.published,error:p.task?.error}); });
        batchSummary($('[data-alert]').dataset.source==='batch');
      } catch(e) { if (version === refreshVersion) notice(e.message,'error'); }
      finally { if (version === refreshVersion) { $('[data-photos]').setAttribute('aria-busy','false'); root.dataset.settled='1';document.dispatchEvent(new Event('sgga:settled')); } }
    }
    function preview(p, cls) {
      const box = el('div',null,cls);
      if (+p.preview && p.state === 'uploaded') {
        const img = el('img'); img.src = p.url; img.alt = p.caption || p.name; img.loading = 'lazy';
        img.addEventListener('error',()=>box.replaceChildren(el('span','预览暂不可用，可打开原文件'))); box.append(img);
      } else box.append(el('span',p.state === 'uploaded' ? '原文件收藏' : '等待照片上传'));
      return box;
    }
    function details(p) {
      const d = el('details',null,'sgga-record'); d.append(el('summary','上传详情'));
      const list = el('dl'); const state = effectiveState(p);
      const values = {
        '文件名':p.name, '上传状态':`${states[state] || state}${state === 'uploaded' && +p.published ? '，已公开展示' : ''}`,
        '上传时间':new Date(+p.created*1000).toLocaleString('zh-CN'), '文件大小':size(p.bytes),
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
      foot.append(el('span',state === 'uploaded' ? (+p.published ? '已展示' : '已上传') : states[state] || state,'sgga-status'));
      foot.dataset.state = state;
      foot.append(button('编辑',()=>editPhoto(p)));
      if (p.state === 'uploaded') { const a = el('a','原图'); a.href=p.url; a.target='_blank'; a.rel='noopener noreferrer'; foot.append(a); }
      info.append(foot);
      if (p.task?.error) info.append(el('p',p.task.error,'sgga-error'));
      if (p.task && state !== 'uploaded' && state !== 'uploading') {
        const actions = el('div',null,'sgga-retry');
        actions.append(button('核查云端', async () => { await api('recover',{task:p.task.id}); notice('已核查云端，记录已更新。'); await refresh(); }));
        actions.append(button('选择原照片重试',()=>{
          if (busy) throw new Error('请等待当前上传结束。');
          const picker=el('input'); picker.type='file';
          picker.addEventListener('change',async()=>{
            if (!picker.files[0]) return;
            queue=[{file:picker.files[0],task:{task:p.task.id,photo:p.id}}]; batchFinished=false; queueRender(); await uploadQueue();
          }); picker.click();
        })); info.append(actions);
      }
      info.append(details(p)); row.append(preview(p,'sgga-photo-preview'),info); return row;
    }
    // Shared date primitive: date-only typed input + owned calendar, immune to host
    // date-icon rules. A popover keeps the calendar above a scrolling edit dialog.
    function bindDate(wrap) {
      const input = wrap.querySelector('input');
      input.min='0001-01-01';input.max='9999-12-31';
      const opener=wrap.querySelector('[data-date-open]');
      const popup=el('div',null,'sgga-calendar');popup.setAttribute('popover','auto');popup.setAttribute('role','dialog');popup.setAttribute('aria-label','选择拍摄日期');popup.hidden=true;
      const uid=`sgga-calendar-${++fieldId}`;popup.id=uid;opener.setAttribute('aria-controls',uid);opener.setAttribute('aria-expanded','false');
      const nativePopover=typeof popup.showPopover==='function';
      const date=(year,month,day)=>{const d=new Date(0);d.setFullYear(year,month,day);d.setHours(12,0,0,0);return d;};
      const iso=d=>`${String(d.getFullYear()).padStart(4,'0')}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
      let focused=new Date(),shownYear=focused.getFullYear(),shownMonth=focused.getMonth(),open=false;
      const header=el('div',null,'sgga-calendar-head'),year=el('input'),month=el('select');
      year.type='number';year.min=1;year.max=9999;year.setAttribute('aria-label','年份');month.setAttribute('aria-label','月份');
      for(let m=0;m<12;m++){const option=el('option',`${m+1}月`);option.value=m;month.append(option);}
      const days=el('div',null,'sgga-calendar-days');days.setAttribute('role','group');days.setAttribute('aria-label','选择日期，方向键移动');
      function close(restore=false){if(!open)return;open=false;if(nativePopover)popup.hidePopover();else popup.hidden=true;opener.setAttribute('aria-expanded','false');if(restore)input.focus();}
      function choose(d){input.value=d?iso(d):'';input.dispatchEvent(new Event('input',{bubbles:true}));input.dispatchEvent(new Event('change',{bubbles:true}));close(true);}
      function render(){
        year.value=shownYear;month.value=shownMonth;days.replaceChildren();
        ['一','二','三','四','五','六','日'].forEach(day=>days.append(el('span',day)));
        const offset=(date(shownYear,shownMonth,1).getDay()+6)%7;
        for(let n=0;n<offset;n++)days.append(el('span',''));
        const count=date(shownYear,shownMonth+1,0).getDate();
        for(let n=1;n<=count;n++){
          const d=date(shownYear,shownMonth,n),value=iso(d),b=button(String(n),()=>choose(d));
          b.dataset.day=value;b.setAttribute('aria-label',`${shownYear}年${shownMonth+1}月${n}日`);b.setAttribute('aria-pressed',String(value===input.value));b.tabIndex=value===iso(focused)?0:-1;days.append(b);
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
        if(d.getFullYear()<1||d.getFullYear()>9999)return;focused=d;shownYear=d.getFullYear();shownMonth=d.getMonth();render();days.querySelector(`[data-day="${iso(d)}"]`)?.focus();
      });
      const foot=el('div',null,'sgga-calendar-foot');foot.append(button('今天',()=>choose(new Date())),button('清空',()=>choose(null)));popup.append(header,days,foot);wrap.append(popup);
      popup.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close(true);}});
      popup.addEventListener('toggle',event=>{if(event.newState==='closed'){open=false;opener.setAttribute('aria-expanded','false');}});
      opener.addEventListener('click',()=>{
        if(open){close(true);return;}
        const parts=input.value.match(/^(\d{4})-(\d{2})-(\d{2})$/);focused=parts?date(+parts[1],+parts[2]-1,+parts[3]):new Date();shownYear=focused.getFullYear();shownMonth=focused.getMonth();render();
        popup.hidden=false;if(nativePopover)popup.showPopover();open=true;opener.setAttribute('aria-expanded','true');
        const rect=input.getBoundingClientRect();const width=Math.min(320,innerWidth-32);popup.style.width=`${width}px`;popup.style.left=`${Math.max(8,Math.min(rect.left,innerWidth-width-8))}px`;popup.style.top=`${Math.max(8,Math.min(rect.bottom+8,innerHeight-popup.offsetHeight-8))}px`;
        days.querySelector('[tabindex="0"]')?.focus();
      });
      if(!nativePopover)document.addEventListener('click',event=>{if(open&&!wrap.contains(event.target))close();});
      wrap.querySelector('[data-date-clear]').addEventListener('click',()=>{ input.value=''; input.dispatchEvent(new Event('input',{bubbles:true})); input.focus(); });
    }
    bindDate($('.sgga-date'));
    function field(label, type, key, value = '') {
      const wrap = el('div',null,'sgga-field'); const labelEl=el('label',label);
      const input=el(type === 'textarea' ? 'textarea' : type === 'select' ? 'select' : 'input');
      input.id=`sgga-field-${++fieldId}`; input.dataset.key=key; input.setAttribute('aria-describedby','sgga-editor-error'); labelEl.htmlFor=input.id;
      if (type === 'select') options(input,value);
      else if (type === 'checkbox') { input.type='checkbox'; input.checked=!!Number(value); wrap.classList.add('sgga-check'); }
      else { if(type !== 'textarea') input.type=type; input.value=value; }
      if (type === 'date') {
        const date=el('div',null,'sgga-date'); const open=el('button','日历'), clear=el('button','清空');
        open.type=clear.type='button'; open.dataset.dateOpen=''; clear.dataset.dateClear=''; open.setAttribute('aria-label','选择拍摄日期'); clear.setAttribute('aria-label','清空拍摄日期');
        date.append(input,open,clear); wrap.append(labelEl,date); bindDate(date);
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
    }
    function showEditor() { editing.original=JSON.stringify(values()); dialog.showModal(); $('[data-editor-fields] input, [data-editor-fields] textarea, [data-editor-fields] select')?.focus(); }
    function editAlbum(a = {}) {
      openEditor('album',a,a.id ? '相册设置' : '新建相册');
      field('相册名称','text','title',a.title||'').required=true;
      field('相册主题 / 简介（选填）','textarea','description',a.description||'');
      field('排序（数字小的在前）','number','sort',a.sort||0);
      field('设为精选相册','checkbox','featured',a.featured||0);
      $('[data-editor-fields]').append(el('p','相册默认公开。上传第一张照片后，即会出现在画廊；封面自动选择，也可在照片编辑中指定。','sgga-meta')); showEditor();
    }
    function editPhoto(p) {
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
    $('[data-save]').addEventListener('click',async()=>{
      const b=$('[data-save]'); const inputValues=values();
      const invalid=[...$('[data-editor-fields]').querySelectorAll('[data-key]')].find(i=>!i.validity.valid);
      if (invalid) { invalid.setAttribute('aria-invalid','true'); $('[data-editor-error]').textContent=invalid.type==='date'?'请填写有效的拍摄日期，或清空日期。':'请填写相册名称。';invalid.focus();return; }
      b.disabled=true; dialog.setAttribute('aria-busy','true');
      const payload={id:editing.record.id||'',revision:editing.record.revision||0,...inputValues};
      if (editing.kind==='album') payload.cover=editing.record.cover||'';
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
    $('[data-all-photos]').addEventListener('click',()=>{$('[data-filter]').value='';page=1;refresh();});
    function updateItem(item, result) {
      if (!item.status) return;
      item.state=result.state; item.error=result.error || '';
      item.done=result.state==='uploaded' && result.photoState==='uploaded' && !!result.published;
      item.status.textContent=item.done ? '上传完成 · 已展示' : states[result.state] || '结果待核查';
      item.row.dataset.state=result.state; item.progress.hidden=item.done;
      if (item.done) item.progress.value=100;
      item.message.textContent=item.error;
    }
    function queueRender() {
      $('[data-queue]').hidden=false;
      $('[data-queue]').replaceChildren(...queue.map(item=>{
        const r=el('div',null,'sgga-queue-row'); item.row=r;
        const name=el('span',item.file.name); name.title=item.file.name;
        item.status=el('span','等待上传'); item.progress=el('progress'); item.progress.max=100; item.progress.value=0; item.progress.setAttribute('aria-label',`${item.file.name} 上传进度`);
        item.message=el('p','', 'sgga-error'); r.append(name,item.status,item.progress,item.message); return r;
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
    function selectFiles(files) { if (busy || !files.length) return; queue=[...files].map(file=>({file})); batchFinished=false; queueRender(); notice(''); }
    $('[data-files]').addEventListener('change',()=>selectFiles($('[data-files]').files));
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
      const controls=['[data-upload]','[data-files]','[data-pick]','[data-upload-fields]'].map($).filter(Boolean);
      busy=true;
      try {
      controls.forEach(control=>control.disabled=true); notice('正在准备上传…');
      for(const item of queue) {
        if(item.done)continue;
        item.progress.hidden=false;
        try {
          item.status.textContent='准备上传…';
          if(!item.task)item.task=await api('prepare',{...metadata,name:item.file.name,bytes:item.file.size});
          const result=await send(item.task.task,item.file,n=>{item.progress.value=n;item.status.textContent=n>=100?'正在保存到云端…':`发送照片 ${n}%`;});
          updateItem(item,result);
          if(!item.done)await reconcile(item,new Error('正在等待云端确认'));
        } catch(e) {
          if(item.task)await reconcile(item,e);
          else updateItem(item,{state:'failed',error:e.message});
        }
      }
      } catch(e) {
        queue.filter(item=>!item.done).forEach(item=>updateItem(item,{state:'failed',error:e.message || '上传未启动，请重新选择照片后重试。'}));
        notice(e.message || '上传未启动，请重试。','error');
      } finally {
        busy=false;controls.forEach(control=>control.disabled=false);
      }
      $('[data-files]').value='';
      batchFinished=true;batchSummary(true);
      $('[data-upload]').disabled=true;
      page=1; $('[data-filter]').value=''; await refresh();
    }
    $('[data-upload]').addEventListener('click',uploadQueue);
    $('[data-refresh]').addEventListener('click',()=>{notice('');refresh();});
    $('[data-filter]').addEventListener('change',()=>{page=1;refresh();});
    $('[data-prev]').addEventListener('click',()=>{if(page>1){page--;refresh();}});
    $('[data-next]').addEventListener('click',()=>{if(page<pages){page++;refresh();}});
    // Suppress only accidental outer-form submission, preserving date/select and IME keys.
    root.addEventListener('keydown',event=>{if(event.key==='Enter' && !event.isComposing && event.target.matches('input[type=text],input[type=number]'))event.preventDefault();});
    root.closest('form')?.addEventListener('submit',event=>{if(root.contains(document.activeElement))event.preventDefault();});
    window.addEventListener('beforeunload',event=>{if(busy || (dialog.open && dirty())){event.preventDefault();event.returnValue='';}});
    refresh();
  });
})();
