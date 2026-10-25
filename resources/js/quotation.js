import html2canvas from 'html2canvas';

window.captureQuotation = async (button) => {
    const documentElement = document.querySelector('.quotation-document');
    if (! documentElement || button.disabled) return;
    button.disabled = true;
    try {
        await document.fonts?.ready;
        await Promise.all(Array.from(documentElement.querySelectorAll('img')).map((image) => image.complete ? Promise.resolve() : new Promise((resolve) => {
            image.addEventListener('load', resolve, { once: true });
            image.addEventListener('error', resolve, { once: true });
        })));
        const canvas = await html2canvas(documentElement, { backgroundColor: '#ffffff', scale: Math.max(2, devicePixelRatio || 1), useCORS: true, logging: false });
        const link = document.createElement('a');
        link.download = button.dataset.filename || 'quotation.png';
        link.href = canvas.toDataURL('image/png', 1);
        link.click();
    } catch (error) {
        console.error('Quotation screenshot failed.', error);
        window.alert('تعذر إنشاء صورة عرض السعر. حاول مرة أخرى.');
    } finally {
        button.disabled = false;
    }
};
