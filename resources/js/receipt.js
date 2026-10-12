import html2canvas from 'html2canvas';

const waitForImages = async (container) => {
    const images = Array.from(container.querySelectorAll('img'));

    await Promise.all(images.map((image) => {
        if (image.complete && image.naturalWidth > 0) {
            return Promise.resolve();
        }

        return new Promise((resolve) => {
            image.addEventListener('load', resolve, { once: true });
            image.addEventListener('error', resolve, { once: true });
        });
    }));
};

window.captureReceipt = async (button) => {
    const receipt = document.querySelector('.receipt');

    if (! receipt || button?.disabled) {
        return;
    }

    const label = button?.querySelector('[data-screenshot-label]');
    const originalLabel = label?.textContent;

    if (button) {
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
    }

    if (label) {
        label.textContent = 'جارٍ إنشاء الصورة...';
    }

    try {
        document.body.classList.add('is-capturing-receipt');

        if (document.fonts?.ready) {
            await document.fonts.ready;
        }

        await waitForImages(receipt);
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        const canvas = await html2canvas(receipt, {
            backgroundColor: '#ffffff',
            scale: Math.max(2, window.devicePixelRatio || 1),
            useCORS: true,
            logging: false,
            scrollX: 0,
            scrollY: 0,
            windowWidth: receipt.scrollWidth,
            windowHeight: receipt.scrollHeight,
        });

        const link = document.createElement('a');
        link.download = button?.dataset.filename || 'invoice.png';
        link.href = canvas.toDataURL('image/png', 1);
        link.click();
    } catch (error) {
        console.error('Receipt screenshot failed.', error);
        window.alert('تعذر إنشاء لقطة الفاتورة. يرجى المحاولة مرة أخرى.');
    } finally {
        document.body.classList.remove('is-capturing-receipt');

        if (button) {
            button.disabled = false;
            button.removeAttribute('aria-busy');
        }

        if (label) {
            label.textContent = originalLabel;
        }
    }
};

const sentCutTokens = new Set();

window.requestReceiptCut = async (printToken) => {
    const { autoCut, cutUrl } = document.body.dataset;

    if (autoCut !== '1' || ! cutUrl || ! printToken || sentCutTokens.has(printToken)) {
        return;
    }

    sentCutTokens.add(printToken);

    try {
        const response = await fetch(cutUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            },
            body: JSON.stringify({ print_token: printToken }),
        });

        if (! response.ok) {
            const payload = await response.json().catch(() => ({}));
            throw new Error(payload.message || 'Receipt cut request failed.');
        }
    } catch (error) {
        console.error('Receipt cutter failed.', error);
    }
};
