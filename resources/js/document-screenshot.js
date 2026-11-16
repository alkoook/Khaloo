import html2canvas from 'html2canvas';

const waitForImages = async (container) => {
    const images = Array.from(container.querySelectorAll('img'));
    await Promise.all(images.map((image) => {
        // A failed image is also `complete`. Waiting for an error event that has
        // already fired leaves screenshot actions permanently pending.
        if (image.complete) return Promise.resolve();

        return new Promise((resolve) => {
            let timeout;
            let settled = false;
            const finish = () => {
                if (settled) return;
                settled = true;
                clearTimeout(timeout);
                image.removeEventListener('load', finish);
                image.removeEventListener('error', finish);
                resolve();
            };

            image.addEventListener('load', finish, { once: true });
            image.addEventListener('error', finish, { once: true });
            timeout = setTimeout(finish, 10000);
            if (image.complete) finish();
        });
    }));
};

export const downloadElementAsPng = async (element, filename) => {
    if (! element) throw new Error('Document element not found.');
    const excludedElements = Array.from(element.querySelectorAll('[data-screenshot-exclude]'));
    const excludedState = excludedElements.map((excluded) => ({
        element: excluded,
        style: excluded.getAttribute('style'),
        ariaHidden: excluded.getAttribute('aria-hidden'),
    }));

    element.setAttribute('data-screenshot-capture-root', '');
    excludedElements.forEach((excluded) => {
        excluded.style.setProperty('display', 'none', 'important');
        excluded.setAttribute('aria-hidden', 'true');
    });

    try {
        if (document.fonts?.ready) await document.fonts.ready;
        await waitForImages(element);
        await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));

        const bounds = element.getBoundingClientRect();
        const width = Math.ceil(Math.max(element.scrollWidth, bounds.width));
        const height = Math.ceil(Math.max(element.scrollHeight, bounds.height));
        const canvas = await html2canvas(element, {
            backgroundColor: '#ffffff',
            scale: Math.max(2, window.devicePixelRatio || 1),
            useCORS: true,
            logging: false,
            scrollX: 0,
            scrollY: 0,
            width,
            height,
            windowWidth: width,
            windowHeight: height,
            onclone: (clonedDocument) => {
                const clonedRoot = clonedDocument.querySelector('[data-screenshot-capture-root]');
                if (clonedRoot) {
                    clonedRoot.style.setProperty('overflow', 'visible', 'important');
                    clonedRoot.style.setProperty('max-height', 'none', 'important');
                }
            },
        });
        const link = document.createElement('a');
        link.download = filename || 'document.png';
        link.href = canvas.toDataURL('image/png', 1);
        document.body.appendChild(link);
        link.click();
        link.remove();
    } finally {
        element.removeAttribute('data-screenshot-capture-root');
        excludedState.forEach(({ element: excluded, style, ariaHidden }) => {
            if (style === null) excluded.removeAttribute('style');
            else excluded.setAttribute('style', style);

            if (ariaHidden === null) excluded.removeAttribute('aria-hidden');
            else excluded.setAttribute('aria-hidden', ariaHidden);
        });
    }
};
