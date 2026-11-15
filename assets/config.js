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
      const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
      try {
        const response = await fetch(url, {method:'POST', body, credentials:'same-origin', cache:'no-store', signal:controller.signal});
        let result; try { result = await response.json(); } catch { throw new Error('服务器响应不完整，请刷新配置页检查设置。'); }
        if (!response.ok || !result.ok) throw new Error(result.error || '设置未保存，请重试。');
        return result.data;
      } catch (error) {
        if (error.name === 'AbortError') throw new Error('请求超时，请刷新配置页检查设置。');
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
      const disabled = controls.map(control=>control.disabled);
      saving=true; controls.forEach(control=>control.disabled=true); form.setAttribute('aria-busy','true'); message('正在保存设置…');
      try {
        const result = await request('config',body);
        Object.entries(result).forEach(([name,value])=>{ const input=form.querySelector(`[name="${name}"]`); if(input)input.value=value; });
        form.querySelectorAll('[data-sgga-secret]').forEach(input=>{ input.value=result.credentialsSet[input.name]?'********':''; hide(input,form.querySelector(`[data-secret-toggle="${input.name}"]`)); });
        savedProvider=provider.value; message('设置已保存。后续上传将使用此配置。');
      } catch(error) { message(error.message,true); }
      finally { controls.forEach((control,index)=>control.disabled=disabled[index]); saving=false;form.setAttribute('aria-busy','false'); }
    });
  });
})();
