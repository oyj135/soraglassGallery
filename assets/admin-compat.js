(() => {
  'use strict';
  if (window.sgGalleryAdminCompat || !document.querySelector('[data-sgga],form[data-sgga-config]')) return;
  window.sgGalleryAdminCompat = true;
  document.documentElement.dataset.sggaPage='1';
  let ready = !!document.querySelector('form[data-sgga-config],[data-sgga][data-settled]'), navigating = false;
  function finishPage() {
    if (!ready || navigating) return;
    // BooAdmin starts NProgress in a delayed jQuery ready callback and only ends
    // it on window.load, which may already have fired. Complete this page's
    // progress through its API; gallery requests have their own pending states.
    const spinner = document.querySelector('#nprogress .spinner-text');
    if (!spinner) return;
    if (window.NProgress?.done) window.NProgress.done(true);
    else spinner.closest('#nprogress')?.remove();
  }
  document.addEventListener('sgga:settled', () => { ready=true; setTimeout(finishPage,0); });
  window.addEventListener('load', () => setTimeout(finishPage,0), {once:true});
  const observer = new MutationObserver(records => {
    if (records.some(record=>[...record.addedNodes].some(node=>node.nodeType===1 && (node.id==='nprogress' || node.closest?.('#nprogress'))))) setTimeout(finishPage,0);
  });
  observer.observe(document.body,{childList:true,subtree:true});
  document.addEventListener('click', event => {
    const anchor=event.target.closest('a[href]');
    if (!anchor) return;
    setTimeout(() => {
      const url=new URL(anchor.href,location.href);
      if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button!==0 || anchor.hasAttribute('download') || anchor.target==='_blank' || (url.pathname===location.pathname && url.search===location.search)) finishPage();
      else if (url.origin===location.origin) navigating=true;
    },0);
  });
  window.addEventListener('beforeunload', event => { setTimeout(()=>{if(event.defaultPrevented){navigating=false;finishPage();}},0); });
  setTimeout(finishPage,0);
})();
