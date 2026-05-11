document.addEventListener('DOMContentLoaded', () => {
    const scanTriggers = Array.from(document.querySelectorAll('#qrScannerBtn, [data-open-qr-scanner]'));
    const modal = document.querySelector('#qrScannerModal');

    if (scanTriggers.length === 0 || !modal) {
        return;
    }
    const closeButtons = Array.from(document.querySelectorAll('.qr-close'));
    const closeSecondary = document.querySelector('#qrScannerCloseSecondary');
    const readerContainer = document.querySelector('#qr-reader');
    const statusMessage = document.querySelector('#qrStatus');
    const assetInfo = document.querySelector('#qrAssetInfo');
    const workerInput = document.querySelector('#qrWorkerName');
    const notesInput = document.querySelector('#qrNotes');
    const logBtn = document.querySelector('#qrLogUsage');
    const scannerError = document.querySelector('#qrScannerError');

    let html5QrCode = null;
    let lastScannedContext = null;

    const parseAssetContext = (decodedText) => {
        if (!decodedText) return null;

        const trimmed = decodedText.trim();
        const payload = {};

        if (trimmed.includes('|') || trimmed.includes('=')) {
            trimmed.split('|').forEach((segment) => {
                const [rawKey, ...rawValueParts] = segment.split('=');
                const key = (rawKey || '').trim();
                const value = rawValueParts.join('=').trim();
                if (key !== '') {
                    payload[key] = decodeURIComponent(value || '');
                }
            });
        }

        if (payload.asset_id) {
            return {
                assetId: parseInt(payload.asset_id, 10) || null,
                unitId: parseInt(payload.unit_id || '', 10) || null,
                unitCode: payload.unit_code || '',
            };
        }

        const assetMatch = trimmed.match(/\/(?:asset|assets)\/(\d+)/i);
        if (assetMatch) {
            return { assetId: parseInt(assetMatch[1], 10), unitId: null, unitCode: '' };
        }

        const numeric = parseInt(trimmed, 10);
        if (Number.isNaN(numeric)) {
            return null;
        }

        return { assetId: numeric, unitId: null, unitCode: '' };
    };

    const setStatus = (message, isError = false) => {
        statusMessage.textContent = message;
        statusMessage.style.color = isError ? '#e74c3c' : '#2c3e50';
    };

    const stopScanner = async () => {
        if (html5QrCode) {
            try {
                await html5QrCode.stop();
            } catch (err) {
            }
            html5QrCode.clear();
            html5QrCode = null;
        }
    };

    const openModal = () => {
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        setStatus('Starting scanner...');
        workerInput.value = '';
        notesInput.value = '';
        assetInfo.textContent = '';
        scannerError.textContent = '';
        lastScannedContext = null;

        if (!window.Html5Qrcode) {
            setStatus('QR scanner library not loaded.', true);
            return;
        }

        html5QrCode = new Html5Qrcode('qr-reader');
        html5QrCode.start(
            { facingMode: 'environment' },
            { fps: 10, qrbox: 250 },
            async (decodedText) => {
                const assetContext = parseAssetContext(decodedText);
                if (!assetContext || !assetContext.assetId) {
                    setStatus('Unrecognized QR format. Please scan a valid asset code.', true);
                    return;
                }

                if (
                    lastScannedContext &&
                    assetContext.assetId === lastScannedContext.assetId &&
                    assetContext.unitId === lastScannedContext.unitId &&
                    assetContext.unitCode === lastScannedContext.unitCode
                ) {
                    return;
                }

                lastScannedContext = assetContext;
                setStatus('QR scanned. Fetching asset...');

                try {
                    const params = new URLSearchParams({ asset_id: String(assetContext.assetId) });
                    if (assetContext.unitId) {
                        params.set('unit_id', String(assetContext.unitId));
                    }
                    if (assetContext.unitCode) {
                        params.set('unit_code', assetContext.unitCode);
                    }

                    const res = await fetch(`/codesamplecaps/get_asset.php?${params.toString()}`);
                    const json = await res.json();
                    if (json.status !== 'success') {
                        throw new Error(json.message || 'Failed to load asset');
                    }

                    const asset = json.asset;
                    assetInfo.innerHTML = `
                        <strong>${asset.asset_name}</strong> (ID: ${asset.id})<br>
                        Unit: ${asset.unit_code || '<em>General asset QR</em>'}<br>
                        Type: ${asset.asset_type || '<em>n/a</em>'}<br>
                        Status: ${asset.asset_status}<br>
                        Serial: ${asset.serial_number || '<em>n/a</em>'}`;
                    setStatus('Asset loaded. Enter worker name and press Log.');
                } catch (err) {
                    setStatus(err.message || 'Failed to load asset.', true);
                }
            },
            (errorMessage) => {
                if (!lastScannedContext) {
                    scannerError.textContent = errorMessage;
                }
            }
        ).catch((err) => {
            setStatus('Camera access denied or unavailable.', true);
        });
    };

    const closeModal = async () => {
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        await stopScanner();
    };

    scanTriggers.forEach((trigger) => {
        trigger.addEventListener('click', openModal);
    });
    closeButtons.forEach((btn) => btn.addEventListener('click', closeModal));
    if (closeSecondary) {
        closeSecondary.addEventListener('click', closeModal);
    }

    logBtn.addEventListener('click', async () => {
        if (!lastScannedContext || !lastScannedContext.assetId) {
            setStatus('Scan an asset QR code first.', true);
            return;
        }

        const workerName = workerInput.value.trim();
        const notes = notesInput.value.trim();

        if (!workerName) {
            setStatus('Worker name is required.', true);
            return;
        }

        setStatus('Logging usage...');

        try {
            const res = await fetch('/codesamplecaps/log_asset_usage.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    asset_id: lastScannedContext.assetId,
                    asset_unit_id: lastScannedContext.unitId,
                    unit_code: lastScannedContext.unitCode,
                    worker_name: workerName,
                    notes,
                    device: navigator.userAgent
                })
            });

            const json = await res.json();
            if (json.status !== 'success') {
                throw new Error(json.message || 'Failed to log usage');
            }

            setStatus('Usage logged successfully.', false);
            window.setTimeout(() => {
                window.location.reload();
            }, 700);
        } catch (err) {
            setStatus(err.message || 'Could not log usage.', true);
        }
    });
});
