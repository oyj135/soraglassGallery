(() => {
  'use strict';
  document.querySelectorAll('form[data-sgga-config]').forEach(form => {
    if (form.dataset.ready) return;
    form.dataset.ready = '1';
    const provider = form.querySelector('[name=provider]');
    let savedProvider = provider.value, saving = false;
    const status = document.createElement('p');
    status.className = 'sgga-config-status'; status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite');
    form.append(status);
    const message = (text, error = false) => { status.textContent = text; status.dataset.error = String(error); };
    async function request(op, body) {
      const url = new URL(form.dataset.endpoint, location.href); url.searchParams.set('op', op);
      if(form.dataset.csrf){url.searchParams.set('_',form.dataset.csrf);body?.set('_',form.dataset.csrf);}
      const controller = new AbortController(), timer = setTimeout(() => controller.abort(), op==='config'?120000:20000);
      try {
        const response = await fetch(url, {method:body?'POST':'GET', body, credentials:'same-origin', cache:'no-store', signal:controller.signal});
        let result; try { result = await response.json(); } catch { throw new Error('服务器响应不完整，请刷新配置页检查设置。'); }
        if (!response.ok || !result.ok) throw new Error(result.error || '设置未保存，请重试。');
        return result.data;
      } catch (error) {
        if (error.name === 'AbortError') {const e=new Error('保存响应超时，结果待核查。');e.uncertain=true;throw e;}
        throw error;
      } finally { clearTimeout(timer); }
    }
    const hide = (input, button) => { input.type='password'; button.setAttribute('aria-label','显示密钥'); button.setAttribute('aria-pressed','false'); };
    form.querySelectorAll('[data-secret-toggle]').forEach(button => {
      const input = form.querySelector(`[data-sgga-secret="${button.dataset.secretToggle}"]`);
      button.addEventListener('click', async () => {
        if (input.type === 'text') { hide(input,button); return; }
        button.disabled = true;
        try {
          if (input.value === '********') {
            if (provider.value !== savedProvider) throw new Error('云存储已切换，请输入对应的新密钥。');
            const data = new FormData(); data.append('field',input.name);
            const result = await request('credential',data);
            input.value = result.value;
          }
          input.type='text'; button.setAttribute('aria-label','隐藏密钥'); button.setAttribute('aria-pressed','true');
        } catch (error) { message(error.message,true); }
        finally { button.disabled = false; }
      });
    });
    provider.addEventListener('change', () => {
      form.querySelectorAll('[data-sgga-secret]').forEach(input => { input.value=''; hide(input,form.querySelector(`[data-secret-toggle="${input.name}"]`)); });
      message('云存储已切换，请填写对应凭据；服务端环境变量仍优先。');
    });
    form.addEventListener('submit', async event => {
      event.preventDefault(); if (saving) return;
      const body = new FormData(form), controls = [...form.querySelectorAll('input,select,button,textarea')];
      const requestId=[...crypto.getRandomValues(new Uint8Array(16))].map(n=>n.toString(16).padStart(2,'0')).join('');body.set('_requestId',requestId);
      const disabled = controls.map(control=>control.disabled);
      saving=true; controls.forEach(control=>control.disabled=true); form.setAttribute('aria-busy','true'); message('正在保存设置…');
      try {
        let result;
        try{result=await request('config',body);}catch(error){
          if(!error.uncertain&&error.name!=='TypeError'&&!error.message.includes('响应不完整'))throw error;
          message('保存响应未确认，正在核查服务器设置…');
          for(let n=0;n<8;n++){if(n)await new Promise(r=>setTimeout(r,1500));try{const current=await request('config-status');if(current.requestId===requestId){result=current;break;}}catch{}}
          if(!result)throw new Error('保存结果尚未确认，请刷新配置页核查。当前输入已保留。');
        }
        Object.entries(result).forEach(([name,value])=>{ const input=form.querySelector(`[name="${name}"]`); if(input)input.value=value; });
        form.querySelectorAll('[data-sgga-secret]').forEach(input=>{ input.value=result.credentialsSet[input.name]?'********':''; hide(input,form.querySelector(`[data-secret-toggle="${input.name}"]`)); });
        savedProvider=provider.value; message('设置已保存。同一存储目标的历史照片也使用当前访问域名。');
      } catch(error) { message(error.message,true); }
      finally { controls.forEach((control,index)=>control.disabled=disabled[index]); saving=false;form.setAttribute('aria-busy','false'); }
    });
  });
})();
