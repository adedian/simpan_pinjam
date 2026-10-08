/*
 * Audit responsif di browser (Phase 15). Tempel di konsol (atau javascript_tool) saat sudah login.
 *
 *   const hasil = await responsiveAudit(['/', '/validasi', '/transaksi/riwayat'], [320, 360, 768]);
 *   console.table(hasil.filter(x => x.over || x.small || x.inFont || x.tiny.length));
 *
 * Halaman dimuat dari fetch() ke iframe srcdoc selebar W piksel (header X-Frame-Options aplikasi memang melarang
 * iframe langsung, jadi isinya disalin). Yang diukur per halaman dan lebar:
 *   over   : lebar halaman melebihi layar (gulir ke samping yang tidak diinginkan); culprits = elemen penyebab
 *   small  : tombol/tautan/isian interaktif dengan sisi < 40px (target sentuh terlalu kecil)
 *   inFont : kolom isian < 16px (iOS memperbesar halaman saat difokus)
 *   tiny   : teks < 12px
 * Catatan: <span> pager (teks "Halaman 1 dari 3") bukan target sentuh dan boleh muncul di "small".
 */
async function responsiveAudit(paths, widths) {
  const all = [];
  for (const W of widths) {
    for (const p of paths) {
      const f = document.createElement('iframe');
      f.style.cssText = 'position:fixed;left:0;top:0;width:' + W + 'px;height:900px;border:0;visibility:hidden';
      document.body.appendChild(f);
      const html = await (await fetch(p, { credentials: 'same-origin' })).text();
      await new Promise((res) => { f.onload = res; f.srcdoc = html; });
      await new Promise((r) => setTimeout(r, 350));
      const d = f.contentDocument, w = f.contentWindow;
      const vis = (e) => { const r = e.getBoundingClientRect(); const cs = w.getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
      const desc = (e) => (e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (typeof e.className === 'string' && e.className ? '.' + e.className.trim().split(/\s+/).slice(0, 2).join('.') : '')).slice(0, 60);
      const inScroller = (e) => { for (let a = e.parentElement; a && a !== d.body; a = a.parentElement) { const o = w.getComputedStyle(a).overflowX; if (o === 'auto' || o === 'scroll') return true; } return false; };
      const over = d.documentElement.scrollWidth - W;
      const culprits = [];
      if (over > 1) {
        d.querySelectorAll('body *').forEach((e) => {
          if (!vis(e) || inScroller(e) || w.getComputedStyle(e).position === 'fixed' || culprits.length >= 4) return;
          const r = e.getBoundingClientRect();
          if (r.right > W + 1) culprits.push(desc(e) + ' r=' + Math.round(r.right));
        });
      }
      const small = [], inFont = [], tiny = [];
      d.querySelectorAll('a.btn, button, input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select:not(.ss__native), textarea, .nav__item, .pager a, summary, a.icon-btn').forEach((e) => {
        if (!vis(e) || e.closest('.sr-only')) return;
        const r = e.getBoundingClientRect();
        if (r.height < 40 || (r.width < 40 && !e.matches('input'))) small.push(desc(e) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
      });
      d.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]), select:not(.ss__native), textarea, .ss__btn').forEach((e) => {
        if (vis(e) && parseFloat(w.getComputedStyle(e).fontSize) < 16) inFont.push(desc(e));
      });
      d.querySelectorAll('body *').forEach((e) => {
        if (!vis(e) || tiny.length >= 3) return;
        const own = Array.from(e.childNodes).some((n) => n.nodeType === 3 && n.textContent.trim().length > 1);
        if (own && parseFloat(w.getComputedStyle(e).fontSize) < 12) tiny.push(desc(e) + ' ' + w.getComputedStyle(e).fontSize);
      });
      all.push({ W, p, over: over > 1 ? over : 0, culprits, small: small.length, smallSample: small.slice(0, 3), inFont: inFont.length, tiny });
      f.remove();
    }
  }
  return all;
}
