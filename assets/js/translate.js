(function(){
  var config = window.GVAIT_TRANSLATE || {};
  if (!config.enabled) return;

  function closest(el, selector) {
    if (!el) return null;
    if (el.closest) return el.closest(selector);
    while (el) {
      if (el.matches && el.matches(selector)) return el;
      el = el.parentElement;
    }
    return null;
  }

  function skipParent(el){
    if (!el) return true;
    return !!closest(el, 'script,style,noscript,svg,canvas,textarea,input,select,option,code,pre,[translate="no"],[data-gvait-no-translate],.gvait-selector,.gvait-no-translate,#wpadminbar');
  }

  function shouldSkipText(t){
    if (!t) return true;
    var s = t.replace(/\s+/g,' ').trim();
    if (s.length < (config.minChars || 2)) return true;
    if (/^[0-9\s.,;:!?()[\]{}'"“”‘’\-+*/\\|@#$%^&_=<>~`]+$/.test(s)) return true;
    if (/^https?:\/\//i.test(s)) return true;
    if (/^[\w.-]+@[\w.-]+\.[a-z]{2,}$/i.test(s)) return true;
    if (/^(true|false|null|undefined|function|var|let|const)$/i.test(s)) return true;
    if (s.length > (config.maxTextLength || 600)) return true;
    return false;
  }

  function collect(){
    if (!document.body || !window.NodeFilter) return {nodes:[], texts:[]};
    var nodes = [];
    var texts = [];
    var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
      acceptNode: function(node){
        if (!node.parentElement || skipParent(node.parentElement)) return NodeFilter.FILTER_REJECT;
        if (shouldSkipText(node.nodeValue)) return NodeFilter.FILTER_REJECT;
        return NodeFilter.FILTER_ACCEPT;
      }
    });

    var max = config.maxNodes || 120;
    while (walker.nextNode() && texts.length < max) {
      var node = walker.currentNode;
      var text = node.nodeValue.replace(/\s+/g,' ').trim();
      if (!text) continue;
      nodes.push(node);
      texts.push(text);
    }
    return {nodes:nodes, texts:texts};
  }

  function apply(nodes, translations){
    if (!translations || !translations.length) return;
    for (var i=0; i<nodes.length; i++) {
      if (!translations[i]) continue;
      var original = nodes[i].nodeValue;
      var leading = (original.match(/^\s+/) || [''])[0];
      var trailing = (original.match(/\s+$/) || [''])[0];
      nodes[i].nodeValue = leading + translations[i] + trailing;
    }
  }

  function run(){
    var data = collect();
    if (!data.texts.length) return;

    var body = new URLSearchParams();
    body.append('action', 'gvait_translate_texts');
    body.append('nonce', config.nonce);
    body.append('lang', config.lang);
    body.append('texts', JSON.stringify(data.texts));

    fetch(config.ajaxUrl, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    }).then(function(r){
      return r.json();
    }).then(function(json){
      if (json && json.success && json.data && json.data.translations) {
        apply(data.nodes, json.data.translations);
      }
    }).catch(function(){});
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
  else run();
})();
