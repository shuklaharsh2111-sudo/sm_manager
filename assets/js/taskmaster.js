(function () {
    var root = document.getElementById('taskMaster');
    if (!root) return;

    var API = root.dataset.api;
    var KEY = 'sme_taskmaster_' + (root.dataset.user || '0');
    var state = {};
    try { state = JSON.parse(localStorage.getItem(KEY)) || {}; } catch (e) { state = {}; }
    function save() { try { localStorage.setItem(KEY, JSON.stringify(state)); } catch (e) {} }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function fmtSize(b) {
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        if (b >= 1024) return Math.round(b / 1024) + ' KB';
        return b + ' B';
    }
    function emptyRow(list, text) {
        list.innerHTML = '';
        list.appendChild(el('li', 'tm-empty', text));
    }

    /* ---------- Simple checklist cards (Calendar, Reports) ---------- */
    function renderChecklist(card) {
        var name = card.dataset.card;
        var list = card.querySelector('.tm-list');
        var tasks = state[name] || [];
        if (!tasks.length) return emptyRow(list, 'No tasks yet');
        list.innerHTML = '';
        tasks.forEach(function (t, i) {
            var li = el('li', 'tm-item' + (t.done ? ' done' : ''));
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = !!t.done;
            cb.addEventListener('change', function () { t.done = cb.checked; save(); renderChecklist(card); });
            var del = el('button', 'tm-del');
            del.type = 'button';
            del.innerHTML = '&times;';
            del.setAttribute('aria-label', 'Delete task');
            del.addEventListener('click', function () { tasks.splice(i, 1); save(); renderChecklist(card); });
            li.append(cb, el('span', null, t.text), del);
            list.appendChild(li);
        });
    }

    /* ---------- Resource cards (Pitch Deck, Mood Board) ---------- */
    function renderItem(card, item) {
        var li = el('li', 'tm-res');
        var thumb = el('div', 'tm-thumb');
        if (item.view_url) {
            var img = document.createElement('img');
            img.src = item.view_url;
            img.alt = '';
            img.loading = 'lazy';
            thumb.appendChild(img);
        } else if (item.has_file) {
            var ext = (item.original_name || '').split('.').pop().slice(0, 4).toUpperCase();
            thumb.textContent = ext || 'FILE';
        } else {
            thumb.textContent = 'LINK';
        }

        var body = el('div', 'tm-res-body');
        body.appendChild(el('span', 'tm-res-title', item.title));
        var meta = item.link_url ? item.link_url : (item.original_name || '') + (item.file_size ? ' · ' + fmtSize(item.file_size) : '');
        body.appendChild(el('span', 'tm-res-meta', meta));

        var actions = el('div', 'tm-res-actions');
        if (item.link_url) {
            var open = el('a', null, 'Open');
            open.href = item.link_url;
            open.target = '_blank';
            open.rel = 'noopener noreferrer';
            actions.appendChild(open);
        }
        if (item.download_url) {
            var dl = el('a', null, item.link_url ? 'Image' : 'Download');
            dl.href = item.download_url;
            dl.setAttribute('download', '');
            actions.appendChild(dl);
        }
        var del = el('button', 'tm-res-del');
        del.type = 'button';
        del.innerHTML = '&times;';
        del.setAttribute('aria-label', 'Delete ' + item.title);
        del.addEventListener('click', function () {
            if (!confirm('Delete "' + item.title + '"?')) return;
            var fd = new FormData();
            fd.append('action', 'delete');
            fd.append('id', item.id);
            fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) { if (d.success) loadCard(card); else alert(d.message || 'Unable to delete.'); })
                .catch(function () { alert('Unable to delete.'); });
        });
        actions.appendChild(del);

        li.append(thumb, body, actions);
        return li;
    }

    function loadCard(card) {
        var list = card.querySelector('.tm-list');
        fetch(API + '?action=list&card=' + encodeURIComponent(card.dataset.apiCard), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.success) return emptyRow(list, d.message || 'Unable to load.');
                if (!d.items.length) return emptyRow(list, 'Nothing added yet');
                list.innerHTML = '';
                d.items.forEach(function (it) { list.appendChild(renderItem(card, it)); });
            })
            .catch(function () { emptyRow(list, 'Unable to load.'); });
    }

    /* ---------- Modal ---------- */
    var modal = document.getElementById('tmModal');
    var uploadForm = document.getElementById('tmUploadForm');
    var linkForm = document.getElementById('tmLinkForm');
    var activeCard = null;
    var HINTS = {
        pitch: { label: 'File', accept: '.pdf,.ppt,.pptx,.key,.doc,.docx,.xls,.xlsx,.csv,.txt,.zip,.jpg,.jpeg,.png,.gif,.webp',
                 hint: 'PDF, PowerPoint, Keynote, Word, Excel, ZIP or image. Max 20 MB.', title: 'Add to Pitch Deck' },
        mood:  { label: 'File or image', accept: '.jpg,.jpeg,.png,.gif,.webp,.pdf,.ppt,.pptx,.zip',
                 hint: 'Images (JPG, PNG, GIF, WebP), PDF, PowerPoint or ZIP. Max 20 MB.', title: 'Add to Mood Board' }
    };

    function setTab(name) {
        modal.querySelectorAll('[data-tm-tab]').forEach(function (b) {
            b.classList.toggle('active', b.dataset.tmTab === name);
        });
        uploadForm.hidden = name !== 'upload';
        linkForm.hidden = name !== 'link';
    }
    function setMsg(form, text, ok) {
        var m = form.querySelector('.tm-msg');
        m.textContent = text || '';
        m.classList.toggle('ok', !!ok);
    }
    function openModal(card) {
        activeCard = card;
        var key = card.dataset.apiCard;
        var cfg = HINTS[key];
        modal.dataset.card = key;
        document.getElementById('tmModalTitle').textContent = cfg.title;
        document.getElementById('tmFileLabel').textContent = cfg.label;
        document.getElementById('tmFileInput').accept = cfg.accept;
        document.getElementById('tmFileHint').textContent = cfg.hint;
        uploadForm.reset(); linkForm.reset();
        setMsg(uploadForm, ''); setMsg(linkForm, '');
        setTab('upload');
        modal.hidden = false;
        uploadForm.querySelector('input[name=title]').focus();
    }
    function closeModal() { modal.hidden = true; activeCard = null; }

    modal.querySelectorAll('[data-tm-close]').forEach(function (b) { b.addEventListener('click', closeModal); });
    modal.querySelectorAll('[data-tm-tab]').forEach(function (b) {
        b.addEventListener('click', function () { setTab(b.dataset.tmTab); });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeModal(); });

    function submitForm(form, action) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            if (!activeCard) return;
            var btn = form.querySelector('button.primary');
            var fd = new FormData(form);
            fd.append('action', action);
            fd.append('card', activeCard.dataset.apiCard);
            btn.disabled = true;
            setMsg(form, 'Saving...', true);
            fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.text().then(function (t) {
                    try { return JSON.parse(t); } catch (x) {
                        return { success: false, message: 'File is probably larger than the server upload limit (php.ini upload_max_filesize / post_max_size).' };
                    }
                }); })
                .then(function (d) {
                    btn.disabled = false;
                    if (!d.success) return setMsg(form, d.message || 'Unable to save.');
                    var card = activeCard;
                    closeModal();
                    loadCard(card);
                })
                .catch(function () { btn.disabled = false; setMsg(form, 'Network error. Please try again.'); });
        });
    }
    submitForm(uploadForm, 'upload');
    submitForm(linkForm, 'add_link');

    /* ---------- Init ---------- */
    root.querySelectorAll('.tm-card').forEach(function (card) {
        if (card.dataset.apiCard) {
            card.querySelector('.tm-add-btn').addEventListener('click', function () { openModal(card); });
            loadCard(card);
        }
    });
})();
