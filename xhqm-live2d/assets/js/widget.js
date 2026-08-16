/* XHQM Live2D 看板娘 前端 */
(async () => {
  const cfg = window.XHQM_L2D;
  if (!cfg) return;

  const $ = id => document.getElementById(id);
  const canvas = $('xhqm-l2d-canvas');
  const W = cfg.width, H = cfg.height;

  /* ---------- 画布（移动端自动缩放） ---------- */
  canvas.width = W; canvas.height = H;
  canvas.style.cssText += `;position:fixed;bottom:0;${cfg.position === 'left' ? 'left' : 'right'}:0;width:${W}px;height:${H}px;z-index:999;pointer-events:none;`;

  let viewScale = 1;
  function fitViewport() {
    const pct = Math.max(20, Math.min(100, cfg.mobile_scale || 100)) / 100;
    // 通用适配：画布不超过屏宽 45%、屏高 60%；小屏再叠加设置的比例
    let s = Math.min(1, (window.innerWidth * 0.45) / W, (window.innerHeight * 0.6) / H);
    if (window.innerWidth < 768) s = Math.min(s, pct);
    viewScale = s;
    canvas.style.transform = `scale(${s})`;
    canvas.style.transformOrigin = cfg.position === 'left' ? 'bottom left' : 'bottom right';
  }
  fitViewport();
  window.addEventListener('resize', fitViewport);

  let config;
  try {
    config = await (await fetch(cfg.rest + '/config')).json();
  } catch (e) { console.error('Live2D 配置加载失败', e); return; }
  if (!config.model_url) { console.error('Live2D 未配置模型'); return; }

  const app = new PIXI.Application({ view: canvas, autoStart: true, transparent: true, width: W, height: H });
  let model;
  try {
    model = await PIXI.live2d.Live2DModel.from(config.model_url);
  } catch (e) { console.error('Live2D 模型加载失败', e); return; }
  app.stage.addChild(model);
  const scale = Math.min(W / model.width, H / model.height) * 0.98;
  model.scale.set(scale);
  model.anchor.set(0.5, 1);
  model.x = W / 2; model.y = H;
  try { model.motion('Idle'); } catch (e) {}

  document.addEventListener('pointermove', e => {
    const r = canvas.getBoundingClientRect();
    // 画布经 CSS transform 缩放，换算回画布内部坐标
    model.focus((e.clientX - r.left) / viewScale, (e.clientY - r.top) / viewScale);
  });

  function expressions() {
    return (model.internalModel.settings.expressions || []).map(x => x.Name);
  }

  /* ---------- MCP 远程指令（轮询服务端队列，执行后回执） ---------- */
  async function execRemoteCommand(cmd) {
    let result = '';
    try {
      switch (cmd.action) {
        case 'expression':
          if (!cmd.value) { model.expression(null); result = '已恢复默认表情'; }
          else if (expressions().includes(cmd.value)) { model.expression(cmd.value); result = '已切换到 ' + cmd.value; }
          else result = '没有这个表情，可用：' + expressions().join('、');
          break;
        case 'motion':
          try { model.motion(cmd.value); result = '已播放动作组 ' + cmd.value; }
          catch (err) { result = '动作播放失败：' + err.message; }
          break;
        case 'speak':
          result = await speak(cmd.value || '');
          break;
        case 'speak_broadcast': // 广播播报：文字进对话框；声音由站点「广播声音」开关决定
          if (config.chat) addMsg('bot', cmd.value || '');
          if (config.bcast_sound) result = await speak(cmd.value || '');
          else result = '已留字（站点关闭了广播声音）';
          break;
        case 'show':
          canvas.style.display = ''; result = '已显示';
          break;
        case 'hide':
          canvas.style.display = 'none'; result = '已隐藏';
          break;
        default:
          result = '未知指令：' + cmd.action;
      }
    } catch (err) {
      result = '执行异常：' + err.message;
    }
    if (cmd.bcast) return; // 广播指令无需回执
    try {
      await fetch(cfg.rest + '/command-result', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id: cmd.id, result }),
      });
    } catch (err) {}
  }

  // 指令按 id 去重：sent 状态的普通指令会在多次轮询间重复出现，广播在窗口期内对所有实例可见
  const seenCmds = new Set();
  setInterval(async () => {
    try {
      const r = await (await fetch(cfg.rest + '/commands')).json();
      const list = r.commands || [];
      list.forEach(cmd => {
        if (!cmd.id || seenCmds.has(cmd.id)) return;
        seenCmds.add(cmd.id);
        execRemoteCommand(cmd);
      });
      // 防内存膨胀：只保留最近一批
      if (seenCmds.size > 500) {
        seenCmds.clear();
        list.forEach(c => { if (c.id) seenCmds.add(c.id); });
      }
    } catch (err) {}
  }, 8000);

  /* ---------- 聊天 ---------- */
  if (!config.chat) return;

  const chatBox = $('xhqm-l2d-chat');
  const chatBody = $('xhqm-l2d-chat-body');
  const chatText = $('xhqm-l2d-chat-text');
  let history = [];   // 发给 API 的 messages（不含 system）
  let sending = false;
  let greeted = false; // 欢迎语只发一次

  function addMsg(cls, text) {
    const div = document.createElement('div');
    div.className = 'xhqm-l2d-msg ' + cls;
    div.textContent = text;
    chatBody.appendChild(div);
    chatBody.scrollTop = chatBody.scrollHeight;
    return div;
  }

  function pageContext() {
    let root = document.querySelector('article') || $('primary') || $('main') || document.body;
    const clone = root.cloneNode(true);
    clone.querySelectorAll('script,style,noscript,#xhqm-l2d-chat').forEach(n => n.remove());
    return { title: document.title, text: clone.innerText.replace(/\s+/g, ' ').trim() };
  }

  /* 语音播放 */
  let audio;
  let audioUnlocked = false;
  // 浏览器自动播放限制：第一次用户交互时解锁 Audio
  document.addEventListener('pointerdown', () => {
    if (audioUnlocked) return;
    audioUnlocked = true;
    const a = new Audio('data:audio/mp3;base64,//uQZAAAAAAAAAAAAAAAAAAAAAAAWGluZwAAAA8AAAACAAACcQCA');
    a.play().then(() => a.pause()).catch(() => {});
  }, { once: false });

  async function speak(text) {
    try {
      const resp = await fetch(cfg.rest + '/tts', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ text }),
      });
      const r = await resp.json();
      if (!r.audio) {
        console.error('TTS 失败：', r);
        return '(语音生成失败：' + (r.message || r.code || '未知错误') + ')';
      }
      if (audio) audio.pause();
      audio = new Audio('data:audio/mp3;base64,' + r.audio);
      const p = audio.play();
      if (p) p.catch(e => console.warn('浏览器拦截了自动播放，请先与页面交互一次', e));
      return '已朗读';
    } catch (e) {
      console.error('TTS 请求出错', e);
      return '(语音请求出错)';
    }
  }

  /* 执行浏览器侧工具 */
  async function execClientTool(call) {
    const name = call.function.name;
    let args = {};
    try { args = JSON.parse(call.function.arguments || '{}'); } catch (e) {}
    let result;
    switch (name) {
      case 'get_model_state':
        const _em = model.internalModel && model.internalModel.motionManager
          ? model.internalModel.motionManager.expressionManager : null;
        const _cur = _em && _em.currentExpression ? _em.currentExpression.Name : null;
        result = JSON.stringify({
          expressions_available: expressions(),
          current_expression: _cur,
          visible: true,
        });
        break;
      case 'set_expression':
        if (!args.expression) { model.expression(null); result = '已恢复默认表情'; }
        else if (expressions().includes(args.expression)) { model.expression(args.expression); result = '已切换到 ' + args.expression; }
        else result = '没有这个表情，可用：' + expressions().join('、');
        break;
      case 'speak':
        result = await speak(args.text || '');
        break;
      default:
        result = '(未知工具)';
    }
    return { tool_call_id: call.id, role: 'tool', name, content: result };
  }

  /* 对话主循环（含工具调用回合） */
  async function chatRound(extraMessages) {
    const resp = await fetch(cfg.rest + '/chat', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        messages: history.concat(extraMessages || []),
        page: pageContext(),
      }),
    });
    const data = await resp.json();
    if (data.type === 'message') return data;

    if (data.type === 'tool_calls') {
      const appended = [data.assistant_message, ...(data.server_results || [])];
      for (const call of data.client_calls || []) {
        appended.push(await execClientTool(call));
      }
      history = history.concat(extraMessages || [], appended);
      return chatRound([]);  // 工具结果回传，再要最终回复
    }
    throw new Error(data.message || data.code || '聊天接口异常');
  }

  async function send() {
    const q = chatText.value.trim();
    if (!q || sending) return;
    sending = true;
    chatText.value = '';
    addMsg('user', q);
    history.push({ role: 'user', content: q });
    //try { model.expression('表情-惊喜'); } catch (e) {}
    const thinking = addMsg('bot', '…');
    try {
      const data = await chatRound([]);
      thinking.textContent = data.reply;
      history.push({ role: 'assistant', content: data.reply });
      if (history.length > 12) history = history.slice(-12);
      if (config.tts) speak(data.reply.replace(/\s+/g, ' ').slice(0, 600));
      //try { model.expression('表情-脸红'); setTimeout(() => model.expression(null), 3000); } catch (e) {}
    } catch (e) {
      thinking.textContent = '(出错了：' + e.message + ')';
    }
    sending = false;
    chatBody.scrollTop = chatBody.scrollHeight;
  }

  $('xhqm-l2d-chat-send').onclick = send;
  chatText.addEventListener('keydown', e => { if (e.key === 'Enter') send(); });
  $('xhqm-l2d-chat-close').onclick = () => { chatBox.style.display = 'none'; };

  /* ---------- 聊天窗口拖动（按住标题栏拖，位置记忆） ---------- */
  const chatHead = $('xhqm-l2d-chat-head');
  const POS_KEY = 'xhqm_l2d_chat_pos';

  function clampPos(x, y) {
    const r = chatBox.getBoundingClientRect();
    x = Math.max(0, Math.min(x, window.innerWidth - r.width));
    y = Math.max(0, Math.min(y, window.innerHeight - 40)); // 标题栏始终够得着
    return [x, y];
  }
  function applyPos(x, y) {
    [x, y] = clampPos(x, y);
    chatBox.style.right = 'auto';
    chatBox.style.bottom = 'auto';
    chatBox.style.left = x + 'px';
    chatBox.style.top = y + 'px';
  }
  // 恢复上次位置
  try {
    const saved = JSON.parse(localStorage.getItem(POS_KEY) || 'null');
    if (saved) applyPos(saved.x, saved.y);
  } catch (e) {}

  chatHead.addEventListener('pointerdown', e => {
    if (e.target.id === 'xhqm-l2d-chat-close') return; // 关闭按钮不触发拖动
    // 靠近窗口边缘时让位给缩放手势
    const br = chatBox.getBoundingClientRect();
    if (e.clientY - br.top <= 7 || e.clientX - br.left <= 7 || br.right - e.clientX <= 7) return;
    e.preventDefault();
    chatHead.classList.add('dragging');
    chatHead.setPointerCapture(e.pointerId);
    const r = chatBox.getBoundingClientRect();
    const dx = e.clientX - r.left, dy = e.clientY - r.top;

    const onMove = ev => applyPos(ev.clientX - dx, ev.clientY - dy);
    const onUp = ev => {
      chatHead.classList.remove('dragging');
      chatHead.removeEventListener('pointermove', onMove);
      chatHead.removeEventListener('pointerup', onUp);
      chatHead.removeEventListener('pointercancel', onUp);
      const rr = chatBox.getBoundingClientRect();
      try { localStorage.setItem(POS_KEY, JSON.stringify({ x: rr.left, y: rr.top })); } catch (err) {}
    };
    chatHead.addEventListener('pointermove', onMove);
    chatHead.addEventListener('pointerup', onUp);
    chatHead.addEventListener('pointercancel', onUp);
  });

  /* ---------- 聊天窗口边缘缩放（记忆尺寸） ---------- */
  const SIZE_KEY = 'xhqm_l2d_chat_size';
  const EDGE = 7, MIN_W = 240, MIN_H = 260;
  const CURSORS = { n: 'ns-resize', s: 'ns-resize', e: 'ew-resize', w: 'ew-resize', ne: 'nesw-resize', sw: 'nesw-resize', nw: 'nwse-resize', se: 'nwse-resize' };
  let resizing = null;

  function edgeAt(e) {
    const r = chatBox.getBoundingClientRect();
    let edge = '';
    if (e.clientY - r.top <= EDGE) edge += 'n';
    if (r.bottom - e.clientY <= EDGE) edge += 's';
    if (e.clientX - r.left <= EDGE) edge += 'w';
    if (r.right - e.clientX <= EDGE) edge += 'e';
    return edge;
  }

  // 恢复上次尺寸
  try {
    const sz = JSON.parse(localStorage.getItem(SIZE_KEY) || 'null');
    if (sz && sz.w >= MIN_W && sz.h >= MIN_H) {
      chatBox.style.maxHeight = 'none';
      chatBox.style.width = sz.w + 'px';
      chatBox.style.height = sz.h + 'px';
    }
  } catch (e) {}

  chatBox.addEventListener('pointermove', e => {
    if (resizing) return;
    chatBox.style.cursor = CURSORS[edgeAt(e)] || '';
  });

  chatBox.addEventListener('pointerdown', e => {
    const edge = edgeAt(e);
    if (!edge || resizing) return;
    if (e.target.closest('#xhqm-l2d-chat-text, #xhqm-l2d-chat-send, #xhqm-l2d-chat-close')) return;
    e.preventDefault();
    const r = chatBox.getBoundingClientRect();
    resizing = { edge, startX: e.clientX, startY: e.clientY, w: r.width, h: r.height, left: r.left, top: r.top };
    chatBox.setPointerCapture(e.pointerId);
    chatBox.style.maxHeight = 'none'; // 改为显式高度驱动

    const onMove = ev => {
      const dx = ev.clientX - resizing.startX, dy = ev.clientY - resizing.startY;
      let w = resizing.w, h = resizing.h, left = resizing.left, top = resizing.top;
      if (edge.includes('e')) w = resizing.w + dx;
      if (edge.includes('s')) h = resizing.h + dy;
      if (edge.includes('w')) { w = resizing.w - dx; left = resizing.left + (resizing.w - w); }
      if (edge.includes('n')) { h = resizing.h - dy; top = resizing.top + (resizing.h - h); }
      w = Math.max(MIN_W, Math.min(w, window.innerWidth * 0.92));
      h = Math.max(MIN_H, Math.min(h, window.innerHeight * 0.92));
      if (edge.includes('w')) left = resizing.left + (resizing.w - w);
      if (edge.includes('n')) top = resizing.top + (resizing.h - h);
      chatBox.style.width = w + 'px';
      chatBox.style.height = h + 'px';
      chatBox.style.right = 'auto'; chatBox.style.bottom = 'auto';
      chatBox.style.left = left + 'px'; chatBox.style.top = top + 'px';
    };
    const onUp = () => {
      chatBox.removeEventListener('pointermove', onMove);
      chatBox.removeEventListener('pointerup', onUp);
      chatBox.removeEventListener('pointercancel', onUp);
      resizing = null;
      chatBox.style.cursor = '';
      const rr = chatBox.getBoundingClientRect();
      try { localStorage.setItem(SIZE_KEY, JSON.stringify({ w: rr.width, h: rr.height })); } catch (err) {}
    };
    chatBox.addEventListener('pointermove', onMove);
    chatBox.addEventListener('pointerup', onUp);
    chatBox.addEventListener('pointercancel', onUp);
  });

  /* 点击模型唤出聊天框（canvas pointer-events:none，用坐标判定） */
  document.addEventListener('click', e => {
    if (chatBox.contains(e.target)) return;
    const r = canvas.getBoundingClientRect();
    if (e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom) {
      const opening = chatBox.style.display !== 'flex';
      chatBox.style.display = opening ? 'flex' : 'none';
      if (opening) {
        // 没拖过位置时，默认出现在模型左/右侧
        if (!localStorage.getItem(POS_KEY)) {
          const cr = canvas.getBoundingClientRect();
          const bx = cfg.position === 'left' ? cr.right + 10 : cr.left - chatBox.offsetWidth - 10;
          applyPos(bx, cr.top - chatBox.offsetHeight + 120);
        }
        //try { model.expression('表情-惊喜'); setTimeout(() => model.expression(null), 2000); } catch (err) {}
        chatText.focus();
        if (!greeted) { greeted = true; addMsg('bot', '你好，欢迎来到这里！'); }
      }
    }
  });
})();
