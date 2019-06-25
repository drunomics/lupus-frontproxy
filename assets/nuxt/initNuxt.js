var contentEl = document.querySelector('main[role="main"]');
var content = contentEl.innerHTML || '';

if (Object.keys(window.__NUXT__.data[0]).length === 0 ) {
  window.__NUXT__.data[0].content = content;
}
else {
  window.__NUXT__.data.push({content: content});
}
