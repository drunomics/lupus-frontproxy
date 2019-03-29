var nodeList = document.querySelectorAll('main.site__content');
var content = '';
if (typeof nodeList[0] !== 'undefined') {
  content = nodeList[0].innerHTML;
  nodeList[0].innerHTML = '';
}

if (Object.keys(window.__NUXT__.data[0]).length === 0 ) {
  window.__NUXT__.data[0].content = content;
}
else {
  window.__NUXT__.data.push({content: content});
}
