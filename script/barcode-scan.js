/*
 * Kamera ilə SN / MAC skan etmə — texniklər əl ilə yazmasın deyə.
 * Xarici kitabxana YOXDUR: brauzerin native BarcodeDetector API-si (internetsiz
 * serverdə işləyir). Dəstəklənmirsə əl ilə yazma qalır (graceful fallback).
 * Kamera yalnız secure context-də (HTTPS və ya localhost) işləyir.
 */
(function () {
    'use strict';

    // SN/MAC input-larını adına görə tap.
    var NAME_RE = /(serial|serial_number|^sn$|_sn$|mac|mac_address)/i;
    var supported = ('BarcodeDetector' in window);

    function isMacField(input) {
        return /mac/i.test(input.name || '') || /mac/i.test(input.placeholder || '');
    }

    function formatMac(raw) {
        var hex = (raw || '').replace(/[^0-9a-fA-F]/g, '').toUpperCase();
        if (hex.length !== 12) { return raw.trim(); }
        return hex.match(/.{2}/g).join(':');
    }

    function attachButtons() {
        var inputs = document.querySelectorAll('input[type="text"], input:not([type])');
        inputs.forEach(function (input) {
            if (input.dataset.scanReady === '1') { return; }
            if (!NAME_RE.test(input.name || '')) { return; }
            input.dataset.scanReady = '1';

            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'scan-btn';
            btn.setAttribute('aria-label', 'Kamera ilə skan et');
            btn.innerHTML = '<i class="fa-solid fa-camera" aria-hidden="true"></i><span>Skan</span>';
            btn.title = supported ? 'Kamera ilə SN/MAC oxu' : 'Bu brauzerdə kamera skanı dəstəklənmir — əl ilə yazın';
            if (!supported) { btn.classList.add('scan-btn-disabled'); }

            // input-u və düyməni bir sarğıya al ki, yan-yana dursun.
            var wrap = document.createElement('span');
            wrap.className = 'scan-field';
            input.parentNode.insertBefore(wrap, input);
            wrap.appendChild(input);
            wrap.appendChild(btn);

            btn.addEventListener('click', function () {
                if (!supported) {
                    alert('Bu brauzer kamera skanını dəstəkləmir. Zəhmət olmasa əl ilə yazın (Android Chrome tövsiyə olunur).');
                    input.focus();
                    return;
                }
                openScanner(input);
            });
        });
    }

    var overlay = null;
    var stream = null;
    var rafId = null;
    var detector = null;

    function buildOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'scan-overlay';
        overlay.innerHTML =
            '<div class="scan-modal" role="dialog" aria-modal="true" aria-label="Kamera skan">' +
            '  <div class="scan-modal-head"><strong>SN / MAC skan et</strong>' +
            '    <button type="button" class="scan-close" aria-label="Bağla">&times;</button></div>' +
            '  <div class="scan-video-wrap"><video class="scan-video" playsinline muted></video>' +
            '    <div class="scan-reticle"></div></div>' +
            '  <p class="scan-hint">Barkodu/QR-u çərçivəyə tutun…</p>' +
            '</div>';
        document.body.appendChild(overlay);
        overlay.querySelector('.scan-close').addEventListener('click', closeScanner);
        overlay.addEventListener('click', function (e) { if (e.target === overlay) { closeScanner(); } });
    }

    function setHint(text, isError) {
        var hint = overlay && overlay.querySelector('.scan-hint');
        if (hint) { hint.textContent = text; hint.classList.toggle('is-error', !!isError); }
    }

    function openScanner(input) {
        if (!overlay) { buildOverlay(); }
        overlay.classList.add('is-open');
        var video = overlay.querySelector('.scan-video');
        setHint('Barkodu/QR-u çərçivəyə tutun…', false);

        try {
            detector = new window.BarcodeDetector({
                formats: ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8', 'data_matrix', 'itf', 'codabar', 'upc_a', 'upc_e']
            });
        } catch (e) {
            detector = new window.BarcodeDetector();
        }

        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                return video.play();
            })
            .then(function () { scanLoop(video, input); })
            .catch(function (err) {
                setHint('Kameraya giriş alınmadı: ' + (err && err.name ? err.name : 'xəta') + '. Əl ilə yazın.', true);
            });
    }

    function scanLoop(video, input) {
        if (!overlay || !overlay.classList.contains('is-open')) { return; }
        detector.detect(video)
            .then(function (codes) {
                if (codes && codes.length) {
                    var value = (codes[0].rawValue || '').trim();
                    if (value) {
                        input.value = isMacField(input) ? formatMac(value) : value;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        if (navigator.vibrate) { navigator.vibrate(120); }
                        setHint('Oxundu: ' + input.value, false);
                        setTimeout(closeScanner, 350);
                        return;
                    }
                }
                rafId = requestAnimationFrame(function () { scanLoop(video, input); });
            })
            .catch(function () {
                rafId = requestAnimationFrame(function () { scanLoop(video, input); });
            });
    }

    function closeScanner() {
        if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        if (overlay) { overlay.classList.remove('is-open'); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attachButtons);
    } else {
        attachButtons();
    }
    // Dinamik yüklənən formalar (AJAX tab/dialoqlar) üçün DOM dəyişikliyini izlə.
    if ('MutationObserver' in window) {
        var pending = null;
        var observer = new MutationObserver(function () {
            if (pending) { return; }
            pending = setTimeout(function () { pending = null; attachButtons(); }, 200);
        });
        observer.observe(document.body, { childList: true, subtree: true });
    } else {
        document.addEventListener('click', function () { setTimeout(attachButtons, 300); });
    }
})();
