(function () {
    'use strict';

    var bootEl = document.getElementById('bootstrapData');
    var BOOT = bootEl ? JSON.parse(bootEl.textContent || '{}') : {};
    var state = {
        admin: BOOT.admin || null,
        payloads: {},
        stats: BOOT.stats || { total: 0, delivered: 0, remaining: 0, mine: 0 },
        attendees: BOOT.attendees || [],
        admins: BOOT.admins || [],
        recentScans: BOOT.recent_scans || [],
        filter: 'all',
        sound: true
    };
    var audioCtx = null;
    var cameraStream = null;
    var cameraFacing = 'environment';
    var scanFrameId = null;
    var lastScan = { uid: '', time: 0 };
    var qrLibPromise = null;
    var jsQrPromise = null;
    var currentTab = 'scanner';

    function $(id) { return document.getElementById(id); }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function toast(message, type) {
        var container = $('toastContainer');
        if (!container) return;
        var box = document.createElement('div');
        var tone = type === 'error' ? 'bg-rose-600' : (type === 'warn' ? 'bg-amber-600' : 'bg-emerald-600');
        box.className = 'pointer-events-auto rounded-lg px-3.5 py-2 text-xs font-medium shadow-md flex items-center justify-between gap-2 text-white ' + tone;
        box.textContent = message;
        container.appendChild(box);
        setTimeout(function () {
            if (box.parentNode) box.parentNode.removeChild(box);
        }, 3000);
    }

    function api(action, payload) {
        return fetch('?api=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': BOOT.csrf || '' },
            body: JSON.stringify(payload || {})
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok || data.ok === false) {
                    var err = new Error(data.error || 'خطا در ارتباط با سرور');
                    err.status = res.status;
                    throw err;
                }
                return data;
            });
        });
    }

    function downloadBlob(blob, filename) {
        var url = URL.createObjectURL(blob);
        var link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        setTimeout(function () { URL.revokeObjectURL(url); }, 4000);
    }

    function downloadFromServer(action, filename) {
        return fetch('?api=' + encodeURIComponent(action), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': BOOT.csrf || '' }
        }).then(function (res) {
            if (!res.ok) {
                return res.json().then(function (d) { throw new Error(d.error || 'دریافت فایل ناموفق بود.'); });
            }
            return res.blob();
        }).then(function (blob) {
            downloadBlob(blob, filename);
        });
    }

    function playSound(type) {
        if (!state.sound) return;
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!audioCtx) audioCtx = new Ctx();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            var now = audioCtx.currentTime;
            if (type === 'ok') {
                var osc = audioCtx.createOscillator();
                var gain = audioCtx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(880, now);
                osc.frequency.setValueAtTime(1320, now + 0.08);
                gain.gain.setValueAtTime(0.2, now);
                gain.gain.exponentialRampToValueAtTime(0.001, now + 0.24);
                osc.connect(gain);
                gain.connect(audioCtx.destination);
                osc.start(now);
                osc.stop(now + 0.25);
            } else {
                for (var i = 0; i < 3; i++) {
                    var bad = audioCtx.createOscillator();
                    var badGain = audioCtx.createGain();
                    bad.type = 'sawtooth';
                    bad.frequency.setValueAtTime(220, now + i * 0.1);
                    badGain.gain.setValueAtTime(0.2, now + i * 0.1);
                    badGain.gain.exponentialRampToValueAtTime(0.01, now + i * 0.1 + 0.08);
                    bad.connect(badGain);
                    badGain.connect(audioCtx.destination);
                    bad.start(now + i * 0.1);
                    bad.stop(now + i * 0.1 + 0.09);
                }
                if (navigator.vibrate) navigator.vibrate([180, 80, 180]);
            }
        } catch (e) {}
    }

    function loadScript(src) {
        return new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = resolve;
            script.onerror = function () { reject(new Error('بارگذاری کتابخانه ناموفق بود.')); };
            document.head.appendChild(script);
        });
    }

    function ensureQrLib() {
        if (!qrLibPromise) {
            qrLibPromise = loadScript('assets/vendor/qrious.min.js').then(function () {
                if (typeof QRious !== 'function') throw new Error('کتابخانه تولید QR بارگذاری نشد.');
            });
        }
        return qrLibPromise;
    }

    function ensureJsQr() {
        if (!jsQrPromise) {
            jsQrPromise = loadScript('assets/vendor/jsQR.min.js').then(function () {
                if (typeof jsQR !== 'function') throw new Error('کتابخانه اسکن QR بارگذاری نشد.');
            });
        }
        return jsQrPromise;
    }

    function switchTab(tabId) {
        currentTab = tabId;
        ['scanner', 'attendees', 'badges', 'admins'].forEach(function (name) {
            var section = $('section_' + name);
            var button = $('tabBtn_' + name);
            if (section) {
                if (name === tabId) section.classList.remove('hidden');
                else section.classList.add('hidden');
            }
            if (button) {
                if (name === tabId) {
                    button.className = 'tab-btn px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm font-semibold border-b-2 border-emerald-600 text-emerald-700 whitespace-nowrap transition';
                } else {
                    button.className = 'tab-btn px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm font-medium border-b-2 border-transparent text-slate-600 hover:text-slate-900 whitespace-nowrap transition';
                }
            }
        });
        if (tabId === 'attendees') renderAttendeesTable();
        if (tabId === 'admins') renderAdminsList();
        if (tabId === 'badges') {
            populateBadgeSelect();
            loadBadgePayloads();
        }
    }

    function setText(id, value) {
        var node = $(id);
        if (node) node.textContent = value;
    }

    function renderStats() {
        var stats = state.stats || {};
        setText('statCardTotal', stats.total || 0);
        setText('statCardDelivered', stats.delivered || 0);
        setText('statCardRemaining', stats.remaining || 0);
        setText('statCardMyCount', stats.mine || 0);
        setText('countFilterAll', stats.total || 0);
        setText('countFilterReceived', stats.delivered || 0);
        setText('countFilterPending', stats.remaining || 0);
        renderRecentScans();
    }

    function renderRecentScans() {
        var container = $('recentScansList');
        if (!container) return;
        var items = state.recentScans || [];
        if (!items.length) {
            container.innerHTML = '<div class="text-xs text-slate-400 text-center py-2">هنوز اسکنی انجام نشده است.</div>';
            return;
        }
        container.innerHTML = items.map(function (item) {
            var ok = item.status === 'ok';
            var dup = item.status === 'already_delivered';
            var color = ok ? 'bg-emerald-100 text-emerald-800' : (dup ? 'bg-rose-100 text-rose-800' : 'bg-amber-100 text-amber-800');
            var label = ok ? 'تحویل شد' : (dup ? 'تکراری' : 'نامعتبر');
            var displayName = escapeHtml(item.full_name || item.uid || '');
            return '<div class="flex items-center justify-between py-1.5 px-2 bg-slate-50 rounded border border-slate-100 text-xs">' +
                '<div class="flex items-center gap-2">' +
                '<span class="text-[10px] font-bold px-1.5 py-0.2 rounded ' + color + '">' + label + '</span>' +
                '<span class="font-semibold text-slate-800">' + displayName + '</span>' +
                '</div>' +
                '<div class="flex items-center gap-2 text-[11px] text-slate-400">' +
                '<span>' + escapeHtml(item.admin_name || '') + '</span>' +
                '<span class="font-mono" dir="ltr">' + escapeHtml(item.time || '') + '</span>' +
                '</div></div>';
        }).join('');
    }

    function applyStats(data) {
        if (data && data.stats) {
            state.stats = data.stats;
            renderStats();
        }
        if (data && data.recent_scans) {
            state.recentScans = data.recent_scans;
            renderRecentScans();
        }
    }

    function showScanResult(result) {
        var box = $('scanResultBox');
        var icon = $('scanResultIcon');
        var badge = $('scanResultBadge');
        var title = $('scanResultTitle');
        var subtitle = $('scanResultSubtitle');
        var grid = $('scanResultDetailsGrid');
        box.classList.remove('hidden');

        if (result.status === 'ok') {
            box.className = 'bg-emerald-50 border-2 border-emerald-500 rounded-xl p-5 shadow-sm transition';
            icon.className = 'w-12 h-12 rounded-xl bg-emerald-600 text-white flex items-center justify-center text-2xl font-bold shrink-0';
            icon.textContent = '✓';
            badge.className = 'inline-block text-[11px] font-bold text-emerald-800 mb-1';
            badge.textContent = 'تحویل موفق';
            title.className = 'text-base font-bold text-emerald-950';
        } else if (result.status === 'already_delivered') {
            box.className = 'bg-rose-50 border-2 border-rose-500 rounded-xl p-5 shadow-sm transition';
            icon.className = 'w-12 h-12 rounded-xl bg-rose-600 text-white flex items-center justify-center text-2xl font-bold shrink-0';
            icon.textContent = '✕';
            badge.className = 'inline-block text-[11px] font-bold text-rose-800 mb-1';
            badge.textContent = 'دریافت تکراری (خطا)';
            title.className = 'text-base font-bold text-rose-950';
        } else {
            box.className = 'bg-amber-50 border-2 border-amber-500 rounded-xl p-5 shadow-sm transition';
            icon.className = 'w-12 h-12 rounded-xl bg-amber-600 text-white flex items-center justify-center text-2xl font-bold shrink-0';
            icon.textContent = '!';
            badge.className = 'inline-block text-[11px] font-bold text-amber-800 mb-1';
            badge.textContent = 'خطا در اسکن';
            title.className = 'text-base font-bold text-amber-950';
        }

        title.textContent = result.status === 'ok' ? 'تأیید شد: تحویل غذا ثبت گردید'
            : (result.status === 'already_delivered' ? 'خطا: قبلاً غذا دریافت کرده است!'
                : (result.status === 'not_found' ? 'فرد در سیستم یافت نشد' : 'کد نامعتبر است'));
        subtitle.textContent = result.message || '';

        $('scanDetailUserId').textContent = (result.attendee && result.attendee.id) ? String(result.attendee.id) : '---';
        if (result.attendee) {
            grid.classList.remove('hidden');
            $('scanDetailAttendee').textContent = result.attendee.full_name || '---';
            $('scanDetailAdminLabel').textContent = result.status === 'already_delivered' ? 'تحویل قبلی توسط:' : 'تحویل داده شده توسط:';
            $('scanDetailAdminName').textContent = result.delivered_by_name || '---';
            $('scanDetailTime').textContent = result.delivered_at || '---';
        } else {
            grid.classList.add('hidden');
        }
    }

    // محتوای QR می‌تواند لینک دفترچه (مثل https://cgco.uicvgame.ir/?q=1%7CABC) یا مستقیم شناسه|امضا باشد
    function extractScanPayload(raw) {
        var text = String(raw || '').trim();
        var qIndex = text.indexOf('?q=');
        if (qIndex === -1) return text;
        var value = text.substring(qIndex + 3);
        var amp = value.indexOf('&');
        if (amp !== -1) value = value.substring(0, amp);
        var hash = value.indexOf('#');
        if (hash !== -1) value = value.substring(0, hash);
        try { value = decodeURIComponent(value); } catch (e) {}
        return value.trim();
    }

    function submitScan(raw, source) {
        var now = Date.now();
        var payload = extractScanPayload(raw);
        if (payload === lastScan.uid && (now - lastScan.time) < 2000) return;
        lastScan = { uid: payload, time: now };
        api('scan', { qr: payload, source: source }).then(function (data) {
            var result = data.result || {};
            showScanResult(result);
            applyStats(data);
            playSound(result.status === 'ok' ? 'ok' : 'error');
        }).catch(function (err) {
            showScanResult({ status: 'invalid', message: err.message || 'خطا در ثبت اسکن.' });
            playSound('error');
        });
    }

    function stopCamera() {
        if (scanFrameId) {
            cancelAnimationFrame(scanFrameId);
            scanFrameId = null;
        }
        if (cameraStream) {
            cameraStream.getTracks().forEach(function (track) { track.stop(); });
            cameraStream = null;
        }
        var video = $('qrVideo');
        if (video) {
            video.pause();
            video.srcObject = null;
            video.classList.add('hidden');
        }
        var overlay = $('scannerOverlayFrame');
        if (overlay) overlay.classList.add('hidden');
        var placeholder = $('cameraPlaceholder');
        if (placeholder) placeholder.classList.remove('hidden');
        var controls = $('cameraActiveControls');
        if (controls) controls.classList.add('hidden');
    }

    function scanLoop() {
        var video = $('qrVideo');
        var canvas = $('qrScanCanvas');
        if (!video || !cameraStream) return;
        if (video.readyState >= 2 && typeof jsQR === 'function') {
            if (video.videoWidth) {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                var ctx = canvas.getContext('2d', { willReadFrequently: true });
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                var data = ctx.getImageData(0, 0, canvas.width, canvas.height);
                var found = jsQR(data.data, data.width, data.height, { inversionAttempts: 'dontInvert' });
                if (found && found.data) {
                    submitScan(found.data, 'camera');
                }
            }
        }
        scanFrameId = requestAnimationFrame(scanLoop);
    }

    function startCamera() {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            toast('دسترسی مستقیم دوربین در این مرورگر فعال نیست.', 'warn');
            return;
        }
        ensureJsQr().then(function () {
            return navigator.mediaDevices.getUserMedia({
                video: { facingMode: cameraFacing, width: { ideal: 640 }, height: { ideal: 640 } },
                audio: false
            });
        }).then(function (stream) {
            stopCamera();
            cameraStream = stream;
            var video = $('qrVideo');
            video.srcObject = stream;
            return video.play().then(function () {
                video.classList.remove('hidden');
                $('scannerOverlayFrame').classList.remove('hidden');
                $('cameraPlaceholder').classList.add('hidden');
                $('cameraActiveControls').classList.remove('hidden');
                scanLoop();
            });
        }).catch(function () {
            toast('خطا در روشن کردن دوربین.', 'warn');
        });
    }

    function filteredAttendees() {
        var query = ($('attendeeSearchInput') ? $('attendeeSearchInput').value : '').trim().toLowerCase();
        return state.attendees.filter(function (item) {
            if (state.filter === 'received' && Number(item.received_food) !== 1) return false;
            if (state.filter === 'pending' && Number(item.received_food) === 1) return false;
            if (query && String(item.full_name).toLowerCase().indexOf(query) === -1) return false;
            return true;
        });
    }

    function renderAttendeesTable() {
        var body = $('attendeesTableBody');
        if (!body) return;
        var rows = filteredAttendees();
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="6" class="py-6 text-center text-slate-400 text-xs">موردی یافت نشد.</td></tr>';
            return;
        }
        body.innerHTML = rows.map(function (item, index) {
            var served = Number(item.received_food) === 1;
            var tag = served
                ? '<span class="px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 font-medium text-[11px]">تحویل شده</span>'
                : '<span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-medium text-[11px]">غذا نگرفته</span>';
            return '<tr class="hover:bg-slate-50 transition">' +
                '<td class="py-2 px-3 font-mono text-slate-400">' + (index + 1) + '</td>' +
                '<td class="py-2 px-3"><span class="font-semibold text-slate-900 block">' + escapeHtml(item.full_name) + '</span></td>' +
                '<td class="py-2 px-3 text-center">' + tag + '</td>' +
                '<td class="py-2 px-3 text-slate-700">' + escapeHtml(item.delivered_by_name || '---') + '</td>' +
                '<td class="py-2 px-3 text-slate-500 font-mono text-[11px]" dir="ltr">' + escapeHtml(item.delivered_at ? String(item.delivered_at).slice(-8) : '---') + '</td>' +
                '<td class="py-2 px-3 text-center"><div class="flex items-center justify-center gap-1">' +
                '<button type="button" data-action="badge" data-id="' + item.id + '" class="px-2 py-0.5 rounded border border-slate-300 hover:bg-slate-100 text-slate-700 text-[11px]">ژتون</button>' +
                '<button type="button" data-action="delete" data-id="' + item.id + '" class="px-2 py-0.5 rounded border border-rose-200 text-rose-600 hover:bg-rose-50 text-[11px]">حذف</button>' +
                '</div></td></tr>';
        }).join('');
    }

    function renderAdminsList() {
        var container = $('adminsListContainer');
        if (!container) return;
        if (!state.admins.length) {
            container.innerHTML = '<div class="text-xs text-slate-400 text-center py-2">موردی یافت نشد.</div>';
            return;
        }
        container.innerHTML = state.admins.map(function (adm) {
            var isSuper = adm.role === 'super_admin';
            var removeBtn = isSuper ? '' : '<button type="button" data-action="delete-admin" data-id="' + adm.id + '" class="text-xs text-rose-600 hover:text-rose-800 font-medium">حذف</button>';
            return '<div class="bg-slate-50 border border-slate-200 rounded-lg p-3 flex items-center justify-between gap-2 text-xs">' +
                '<div><div class="flex items-center gap-2">' +
                '<strong class="text-slate-800">' + escapeHtml(adm.full_name) + '</strong>' +
                '<span class="text-[10px] px-1.5 py-0.2 rounded ' + (isSuper ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-700') + '">' + (isSuper ? 'ادمین اصلی' : 'ادمین تحویل') + '</span>' +
                '</div><div class="text-slate-500 text-[11px] mt-0.5">نام کاربری: <code class="font-mono text-slate-700">' + escapeHtml(adm.username) + '</code></div></div>' +
                '<div class="flex items-center gap-3"><span class="text-slate-700">تعداد تحویل غذا: <strong>' + (adm.delivered_count || 0) + '</strong></span>' + removeBtn + '</div></div>';
        }).join('');
    }

    function populateBadgeSelect() {
        var select = $('badgeAttendeeSelect');
        if (!select) return;
        var current = select.value;
        select.innerHTML = state.attendees.map(function (item, index) {
            return '<option value="' + item.id + '">' + String(index + 1).padStart(3, '0') + ' — ' + escapeHtml(item.full_name) + '</option>';
        }).join('');
        if (current && state.attendees.some(function (item) { return String(item.id) === String(current); })) {
            select.value = current;
        }
    }

    function ensurePayloads() {
        if (state.payloads && Object.keys(state.payloads).length) {
            return Promise.resolve();
        }
        return api('badge_payloads', {}).then(function (data) {
            state.payloads = data.payloads || {};
        }).catch(function () {});
    }

    function loadBadgePayloads() {
        ensurePayloads().then(function () {
            updateBadgePreview();
        });
    }

    var GUIDE_BASE_URL = 'https://cgco.uicvgame.ir/';
    var GUIDE_HOST = 'cgco.uicvgame.ir';
    var CARD_W = 720;  // 6 سانتی‌متر با مقیاس 120px بر سانتی‌متر (~300DPI)
    var CARD_H = 960;  // 8 سانتی‌متر

    function makeQr(value, size) {
        var c = document.createElement('canvas');
        if (typeof QRious === 'function') {
            new QRious({
                element: c,
                value: value,
                size: size,
                level: 'H',
                background: '#ffffff',
                foreground: '#2e2a22',
                padding: 0
            });
        }
        return c;
    }

    function fitFontSize(ctx, text, maxWidth, baseSize) {
        var size = baseSize;
        ctx.font = size + 'px IRANSansFaNum, sans-serif';
        while (ctx.measureText(text).width > maxWidth && size > 11) {
            size -= 1;
            ctx.font = size + 'px IRANSansFaNum, sans-serif';
        }
        return size;
    }

    function drawBadge(canvas, attendee) {
        canvas.width = CARD_W;
        canvas.height = CARD_H;
        var ctx = canvas.getContext('2d');
        ctx.textAlign = 'center';

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, CARD_W, CARD_H);
        ctx.strokeStyle = '#e3d2a6';
        ctx.lineWidth = 8;
        ctx.strokeRect(20, 20, CARD_W - 40, CARD_H - 40);

        var payload = state.payloads[attendee.id] || attendee.qr_payload || '';
        var qrValue = GUIDE_BASE_URL + '?q=' + encodeURIComponent(payload);

        ctx.fillStyle = '#ffffff';
        ctx.strokeStyle = '#ecdfc0';
        ctx.lineWidth = 2;
        ctx.strokeRect(140, 150, 440, 440);
        ctx.drawImage(makeQr(qrValue, 400), 160, 170, 400, 400);

        ctx.fillStyle = '#a08c5d';
        ctx.font = '28px IRANSansFaNum, sans-serif';
        ctx.fillText(GUIDE_HOST, 360, 650);

        ctx.fillStyle = '#5c4a1e';
        ctx.font = 'bold 56px IRANSansFaNum, sans-serif';
        ctx.fillText('دفترچه راهنمای آنلاین', 360, 724);

        var nameSize = fitFontSize(ctx, attendee.full_name, 620, 28);
        ctx.fillStyle = '#8a7548';
        ctx.font = nameSize + 'px IRANSansFaNum, sans-serif';
        ctx.fillText(attendee.full_name, 360, 896);
    }

    function updateBadgePreview(force) {
        var select = $('badgeAttendeeSelect');
        var canvas = $('singleBadgeCanvas');
        if (!canvas || !state.attendees.length) return;
        var index = 0;
        if (select && select.value) {
            var found = state.attendees.findIndex(function (item) { return String(item.id) === String(select.value); });
            if (found !== -1) index = found;
        }
        var attendee = state.attendees[index];
        if (!force && !state.payloads[attendee.id]) return;
        $('badgePreviewIndexTag').textContent = 'ژتون ' + attendee.full_name;
        ensureQrLib().then(function () {
            if (document.fonts && document.fonts.load) {
                return document.fonts.load('bold 30px IRANSansFaNum').catch(function () {});
            }
        }).then(function () {
            drawBadge(canvas, attendee);
        }).catch(function () {
            drawBadge(canvas, attendee);
        });
    }

    function canvasToBytes(canvas) {
        return new Promise(function (resolve, reject) {
            if (canvas.toBlob) {
                canvas.toBlob(function (blob) {
                    if (!blob) { reject(new Error('تولید تصویر ناموفق بود.')); return; }
                    blob.arrayBuffer().then(function (buf) { resolve(new Uint8Array(buf)); });
                }, 'image/png');
                return;
            }
            var data = canvas.toDataURL('image/png').split(',')[1];
            var binary = atob(data);
            var bytes = new Uint8Array(binary.length);
            for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
            resolve(bytes);
        });
    }

    var crcTable = null;
    function crc32(bytes) {
        if (!crcTable) {
            crcTable = new Uint32Array(256);
            for (var i = 0; i < 256; i++) {
                var c = i;
                for (var k = 0; k < 8; k++) c = (c & 1) ? (0xEDB88320 ^ (c >>> 1)) : (c >>> 1);
                crcTable[i] = c >>> 0;
            }
        }
        var crc = 0xFFFFFFFF;
        for (var j = 0; j < bytes.length; j++) crc = crcTable[(crc ^ bytes[j]) & 0xFF] ^ (crc >>> 8);
        return (crc ^ 0xFFFFFFFF) >>> 0;
    }

    function buildZip(files) {
        var encoder = new TextEncoder();
        var parts = [];
        var central = [];
        var offset = 0;
        var now = new Date();
        var dosTime = ((now.getHours() & 31) << 11) | ((now.getMinutes() & 63) << 5) | ((now.getSeconds() / 2) & 31);
        var dosDate = (((now.getFullYear() - 1980) & 127) << 9) | (((now.getMonth() + 1) & 15) << 5) | (now.getDate() & 31);

        files.forEach(function (file) {
            var name = encoder.encode(file.name);
            var data = file.data;
            var checksum = crc32(data);
            var local = new Uint8Array(30 + name.length);
            var lv = new DataView(local.buffer);
            lv.setUint32(0, 0x04034b50, true);
            lv.setUint16(4, 20, true);
            lv.setUint16(6, 0x0800, true);
            lv.setUint16(8, 0, true);
            lv.setUint16(10, dosTime, true);
            lv.setUint16(12, dosDate, true);
            lv.setUint32(14, checksum, true);
            lv.setUint32(18, data.length, true);
            lv.setUint32(22, data.length, true);
            lv.setUint16(26, name.length, true);
            lv.setUint16(28, 0, true);
            local.set(name, 30);
            parts.push(local, data);

            var entry = new Uint8Array(46 + name.length);
            var ev = new DataView(entry.buffer);
            ev.setUint32(0, 0x02014b50, true);
            ev.setUint16(4, 20, true);
            ev.setUint16(6, 20, true);
            ev.setUint16(8, 0x0800, true);
            ev.setUint16(10, 0, true);
            ev.setUint16(12, dosTime, true);
            ev.setUint16(14, dosDate, true);
            ev.setUint32(16, checksum, true);
            ev.setUint32(20, data.length, true);
            ev.setUint32(24, data.length, true);
            ev.setUint16(28, name.length, true);
            ev.setUint32(42, offset, true);
            entry.set(name, 46);
            central.push(entry);

            offset += local.length + data.length;
        });

        var centralSize = central.reduce(function (sum, item) { return sum + item.length; }, 0);
        var end = new Uint8Array(22);
        var ev = new DataView(end.buffer);
        ev.setUint32(0, 0x06054b50, true);
        ev.setUint16(8, files.length, true);
        ev.setUint16(10, files.length, true);
        ev.setUint32(12, centralSize, true);
        ev.setUint32(16, offset, true);

        var all = parts.concat(central, [end]);
        var total = all.reduce(function (sum, item) { return sum + item.length; }, 0);
        var output = new Uint8Array(total);
        var cursor = 0;
        all.forEach(function (chunk) {
            output.set(chunk, cursor);
            cursor += chunk.length;
        });
        return new Blob([output], { type: 'application/zip' });
    }

    function downloadSingleBadge() {
        if (!state.attendees.length) return;
        var select = $('badgeAttendeeSelect');
        var index = 0;
        if (select && select.value) {
            var found = state.attendees.findIndex(function (item) { return String(item.id) === String(select.value); });
            if (found !== -1) index = found;
        }
        var attendee = state.attendees[index];
        ensureQrLib().then(function () {
            return ensurePayloads();
        }).then(function () {
            var work = document.createElement('canvas');
            drawBadge(work, attendee);
            return canvasToBytes(work);
        }).then(function (bytes) {
            var safe = attendee.full_name.replace(/[\\/:*?"<>|\s]+/g, '_');
            downloadBlob(new Blob([bytes], { type: 'image/png' }), String(index + 1).padStart(3, '0') + '_' + safe + '.png');
            toast('فایل PNG دانلود شد.', 'ok');
        }).catch(function (err) {
            toast(err.message || 'خطا در ساخت تصویر ژتون.', 'error');
        });
    }

    function downloadAllBadgesZip() {
        if (!state.attendees.length) return;
        var box = $('zipProgressBarBox');
        var text = $('zipProgressText');
        var percent = $('zipProgressPercent');
        var fill = $('zipProgressFill');
        if (box) box.classList.remove('hidden');

        ensureQrLib().then(function () {
            return ensurePayloads();
        }).then(function () {
            var files = [];
            var work = document.createElement('canvas');
            var total = state.attendees.length;
            var index = 0;

            function step() {
                if (index >= total) {
                    var blob = buildZip(files);
                    downloadBlob(blob, 'Food_Coupons_' + total + '_Sequential.zip');
                    if (box) setTimeout(function () { box.classList.add('hidden'); }, 900);
                    toast('فایل ZIP با موفقیت دانلود شد.', 'ok');
                    return;
                }
                var attendee = state.attendees[index];
                drawBadge(work, attendee);
                canvasToBytes(work).then(function (bytes) {
                    var safe = attendee.full_name.replace(/[\\/:*?"<>|\s]+/g, '_');
                    files.push({ name: 'Coupons/' + String(index + 1).padStart(3, '0') + '_' + safe + '.png', data: bytes });
                    index++;
                    var pct = Math.round((index / total) * 100);
                    if (text) text.textContent = 'تولید ژتون ' + index + ' از ' + total + '...';
                    if (percent) percent.textContent = pct + '%';
                    if (fill) fill.style.width = pct + '%';
                    setTimeout(step, 0);
                });
            }
            step();
        }).catch(function (err) {
            if (box) box.classList.add('hidden');
            toast(err.message || 'خطا در ساخت فایل ZIP.', 'error');
        });
    }

    function printBadges() {
        if (!state.attendees.length) return;
        ensureQrLib().then(function () {
            return ensurePayloads();
        }).then(function () {
            var container = $('printSheetContainer');
            var work = document.createElement('canvas');
            var limit = Math.min(state.attendees.length, 60);
            var html = '<div class="lanyard-print-grid">';
            for (var i = 0; i < limit; i++) {
                drawBadge(work, state.attendees[i]);
                html += '<div class="lanyard-card-item p-1 text-center"><img src="' + work.toDataURL('image/png') + '" class="lanyard-card-image" alt="card"></div>';
            }
            html += '</div>';
            container.innerHTML = html;
            window.print();
        }).catch(function (err) {
            toast(err.message || 'خطا در آماده‌سازی چاپ.', 'error');
        });
    }

    function importExcel(file) {
        var form = new FormData();
        form.append('csrf_token', BOOT.csrf || '');
        form.append('file', file);
        fetch('?api=import_excel', {
            method: 'POST',
            credentials: 'same-origin',
            body: form
        }).then(function (res) {
            return res.json().then(function (data) {
                if (!res.ok || data.ok === false) throw new Error(data.error || 'خطا در بارگذاری فایل.');
                return data;
            });
        }).then(function (data) {
            state.attendees = data.attendees || [];
            state.stats = data.stats || state.stats;
            renderStats();
            renderAttendeesTable();
            populateBadgeSelect();
            toast(data.added + ' نفر از فایل اکسل با موفقیت اضافه شدند.', 'ok');
        }).catch(function (err) {
            toast(err.message || 'خطا در خواندن فایل اکسل.', 'error');
        });
    }

    function closeUserMenu() {
        var menu = $('userMenu');
        var button = $('btnUserMenu');
        if (menu) menu.classList.add('hidden');
        if (button) button.setAttribute('aria-expanded', 'false');
    }

    function toggleUserMenu() {
        var menu = $('userMenu');
        var button = $('btnUserMenu');
        if (!menu || !button) return;
        var open = menu.classList.contains('hidden');
        menu.classList.toggle('hidden', !open);
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function bindEvents() {
        var loginForm = $('mainLoginForm');
        if (loginForm) {
            loginForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var username = $('loginUsername').value.trim();
                var password = $('loginPassword').value;
                var errorBox = $('loginError');
                var button = $('loginSubmitBtn');
                errorBox.classList.add('hidden');
                button.disabled = true;
                api('login', { username: username, password: password }).then(function () {
                    location.reload();
                }).catch(function (err) {
                    button.disabled = false;
                    errorBox.textContent = err.message || 'ورود ناموفق بود.';
                    errorBox.classList.remove('hidden');
                });
            });
        }

        var userMenuBtn = $('btnUserMenu');
        if (userMenuBtn) {
            userMenuBtn.addEventListener('click', function (event) {
                event.stopPropagation();
                toggleUserMenu();
            });
            document.addEventListener('click', function (event) {
                var menu = $('userMenu');
                if (menu && !menu.classList.contains('hidden') && !menu.contains(event.target)) {
                    closeUserMenu();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') closeUserMenu();
            });
        }

        var logoutBtn = $('btnLogout');
        if (logoutBtn) {
            logoutBtn.addEventListener('click', function () {
                closeUserMenu();
                stopCamera();
                api('logout', {}).catch(function () {}).then(function () {
                    location.reload();
                });
            });
        }

        var changeBtn = $('btnChangePassword');
        if (changeBtn) {
            changeBtn.addEventListener('click', function () {
                closeUserMenu();
                $('passwordModal').classList.remove('hidden');
                $('passwordError').classList.add('hidden');
                $('currentPasswordInput').value = '';
                $('newPasswordInput').value = '';
                $('confirmPasswordInput').value = '';
            });
        }
        var closePassword = $('btnClosePasswordModal');
        if (closePassword) {
            closePassword.addEventListener('click', function () {
                $('passwordModal').classList.add('hidden');
            });
        }
        var passwordForm = $('changePasswordForm');
        if (passwordForm) {
            passwordForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var errorBox = $('passwordError');
                errorBox.classList.add('hidden');
                api('change_password', {
                    current_password: $('currentPasswordInput').value,
                    new_password: $('newPasswordInput').value,
                    confirm_password: $('confirmPasswordInput').value
                }).then(function (data) {
                    $('passwordModal').classList.add('hidden');
                    if (data && data.env_updated === false) {
                        toast('رمز عبور تغییر کرد، ولی فایل .env قابل به‌روزرسانی نبود.', 'warn');
                    } else {
                        toast('رمز عبور با موفقیت تغییر کرد.', 'ok');
                    }
                }).catch(function (err) {
                    errorBox.textContent = err.message || 'تغییر رمز ناموفق بود.';
                    errorBox.classList.remove('hidden');
                });
            });
        }

        var nav = $('mainNavTabs');
        if (nav) {
            nav.addEventListener('click', function (event) {
                var button = event.target.closest('.tab-btn');
                if (button) switchTab(button.getAttribute('data-tab'));
            });
        }

        var soundBtn = $('btnSoundToggle');
        if (soundBtn) {
            soundBtn.addEventListener('click', function () {
                state.sound = !state.sound;
                $('soundIcon').textContent = state.sound ? '🔊' : '🔇';
                $('soundLabel').textContent = state.sound ? 'صدا: فعال' : 'صدا: بی‌صدا';
            });
        }

        var startBtn = $('btnStartCamera');
        if (startBtn) startBtn.addEventListener('click', startCamera);
        var stopBtn = $('btnStopCamera');
        if (stopBtn) stopBtn.addEventListener('click', stopCamera);
        var switchBtn = $('btnSwitchCamera');
        if (switchBtn) {
            switchBtn.addEventListener('click', function () {
                cameraFacing = cameraFacing === 'environment' ? 'user' : 'environment';
                startCamera();
            });
        }

        var manualForm = $('manualScanForm');
        if (manualForm) {
            manualForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var input = $('manualCodeInput');
                var value = input.value.trim();
                if (!value) return;
                lastScan = { uid: '', time: 0 };
                submitScan(value, 'manual');
                input.value = '';
            });
        }

        var searchInput = $('attendeeSearchInput');
        if (searchInput) searchInput.addEventListener('input', renderAttendeesTable);

        var filterBar = document.querySelectorAll('.filter-btn');
        Array.prototype.forEach.call(filterBar, function (button) {
            button.addEventListener('click', function () {
                state.filter = button.getAttribute('data-filter');
                Array.prototype.forEach.call(filterBar, function (item) {
                    if (item === button) {
                        item.className = 'filter-btn px-2.5 py-1 rounded-lg text-xs font-semibold bg-emerald-600 text-white';
                    } else {
                        item.className = 'filter-btn px-2.5 py-1 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-100';
                    }
                });
                renderAttendeesTable();
            });
        });

        var tableBody = $('attendeesTableBody');
        if (tableBody) {
            tableBody.addEventListener('click', function (event) {
                var button = event.target.closest('button[data-action]');
                if (!button) return;
                var id = parseInt(button.getAttribute('data-id'), 10);
                if (button.getAttribute('data-action') === 'delete') {
                    api('delete_attendee', { id: id }).then(function (data) {
                        state.attendees = data.attendees || [];
                        state.stats = data.stats || state.stats;
                        renderStats();
                        renderAttendeesTable();
                        populateBadgeSelect();
                        toast('شرکت‌کننده حذف شد.', 'warn');
                    }).catch(function (err) { toast(err.message, 'error'); });
                } else if (button.getAttribute('data-action') === 'badge') {
                    switchTab('badges');
                    var select = $('badgeAttendeeSelect');
                    if (select) {
                        select.value = String(id);
                        updateBadgePreview();
                    }
                }
            });
        }

        var addForm = $('addAttendeeForm');
        if (addForm) {
            addForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var input = $('newAttName');
                var fullName = input.value.trim();
                if (!fullName) return;
                api('add_attendee', { full_name: fullName }).then(function (data) {
                    state.attendees = data.attendees || [];
                    state.stats = data.stats || state.stats;
                    input.value = '';
                    renderStats();
                    renderAttendeesTable();
                    populateBadgeSelect();
                    toast('شرکت‌کننده با موفقیت اضافه شد.', 'ok');
                }).catch(function (err) { toast(err.message, 'error'); });
            });
        }

        var uploadInput = $('excelUploadInput');
        if (uploadInput) {
            uploadInput.addEventListener('change', function () {
                if (uploadInput.files && uploadInput.files[0]) {
                    importExcel(uploadInput.files[0]);
                }
                uploadInput.value = '';
            });
        }

        var sampleBtn = $('btnSampleExcel');
        if (sampleBtn) {
            sampleBtn.addEventListener('click', function () {
                downloadFromServer('sample_excel', 'sample_attendees.xlsx')
                    .catch(function (err) { toast(err.message, 'error'); });
            });
        }

        var exportBtn = $('btnExportExcel');
        if (exportBtn) {
            exportBtn.addEventListener('click', function () {
                downloadFromServer('export_excel', 'food_delivery_report.xlsx')
                    .catch(function (err) { toast(err.message, 'error'); });
            });
        }

        var openDelete = $('btnOpenDeleteAll');
        if (openDelete) {
            openDelete.addEventListener('click', function () {
                $('deleteAllModal').classList.remove('hidden');
            });
        }
        var closeDelete = $('btnCloseDeleteAll');
        if (closeDelete) {
            closeDelete.addEventListener('click', function () {
                $('deleteAllModal').classList.add('hidden');
            });
        }
        var deleteAll = $('btnDeleteAllAttendees');
        if (deleteAll) {
            deleteAll.addEventListener('click', function () {
                api('delete_all_attendees', {}).then(function (data) {
                    state.attendees = data.attendees || [];
                    state.stats = data.stats || state.stats;
                    state.recentScans = [];
                    $('deleteAllModal').classList.add('hidden');
                    renderStats();
                    renderAttendeesTable();
                    populateBadgeSelect();
                    toast('همه افراد حذف شدند.', 'warn');
                }).catch(function (err) { toast(err.message, 'error'); });
            });
        }
        var resetBtn = $('btnResetFoodStatuses');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                api('reset_food_statuses', {}).then(function (data) {
                    state.attendees = data.attendees || [];
                    state.stats = data.stats || state.stats;
                    state.recentScans = [];
                    $('deleteAllModal').classList.add('hidden');
                    renderStats();
                    renderAttendeesTable();
                    toast('وضعیت تحویل همه افراد ریست شد.', 'ok');
                }).catch(function (err) { toast(err.message, 'error'); });
            });
        }

        var badgeSelect = $('badgeAttendeeSelect');
        if (badgeSelect) badgeSelect.addEventListener('change', updateBadgePreview);
        var singleBtn = $('btnSingleBadge');
        if (singleBtn) singleBtn.addEventListener('click', downloadSingleBadge);
        var zipBtn = $('btnAllBadgesZip');
        if (zipBtn) zipBtn.addEventListener('click', downloadAllBadgesZip);
        var printBtn = $('btnPrintBadges');
        if (printBtn) printBtn.addEventListener('click', printBadges);

        var addAdminForm = $('addAdminForm');
        if (addAdminForm) {
            addAdminForm.addEventListener('submit', function (event) {
                event.preventDefault();
                var fullName = $('newAdminFullName').value.trim();
                var username = $('newAdminUsername').value.trim();
                var password = $('newAdminPassword').value;
                if (!fullName || !username || !password) return;
                api('add_admin', { full_name: fullName, username: username, password: password })
                    .then(function (data) {
                        state.admins = data.admins || [];
                        $('newAdminFullName').value = '';
                        $('newAdminUsername').value = '';
                        $('newAdminPassword').value = '';
                        renderAdminsList();
                        toast('ادمین تحویل غذا اضافه شد.', 'ok');
                    }).catch(function (err) { toast(err.message, 'error'); });
            });
        }

        var adminsList = $('adminsListContainer');
        if (adminsList) {
            adminsList.addEventListener('click', function (event) {
                var button = event.target.closest('button[data-action="delete-admin"]');
                if (!button) return;
                var id = parseInt(button.getAttribute('data-id'), 10);
                api('delete_admin', { id: id }).then(function (data) {
                    state.admins = data.admins || [];
                    renderAdminsList();
                    toast('ادمین حذف شد.', 'warn');
                }).catch(function (err) { toast(err.message, 'error'); });
            });
        }

        window.addEventListener('beforeunload', stopCamera);
    }

    function init() {
        bindEvents();
        if (!state.admin) return;
        renderStats();
        renderAttendeesTable();
        renderAdminsList();
        switchTab('scanner');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
