var nodeList = document.querySelectorAll('main.site__content');
var content = '';
if (typeof nodeList[0] !== 'undefined') {
  content = nodeList[0].innerHTML;
  nodeList[0].innerHTML = '';
}
window.__NUXT__={layout:"default",data:[{content: content}],error:null,serverRendered:!0}