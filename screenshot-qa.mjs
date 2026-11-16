const tabs = await (await fetch('http://127.0.0.1:9223/json/list')).json();
const tab = tabs.find((candidate) => candidate.url.includes('screenshot-qa.html'));
if (!tab) throw new Error('QA tab not found.');

const socket = new WebSocket(tab.webSocketDebuggerUrl);
const timeout = setTimeout(() => {
    console.error('CDP timeout.');
    process.exit(1);
}, 40000);

socket.addEventListener('open', () => {
    socket.send(JSON.stringify({
        id: 1,
        method: 'Runtime.evaluate',
        params: {
            expression: `(async () => {
                document.querySelector('[data-statement-screenshot]').click();
                const startedAt = Date.now();
                while (document.body.dataset.complete !== 'true' && !document.body.dataset.failure && Date.now() - startedAt < 30000) {
                    await new Promise((resolve) => setTimeout(resolve, 100));
                }
                await new Promise((resolve) => setTimeout(resolve, 500));
                return JSON.stringify({
                    dataset: { ...document.body.dataset },
                    excludedVisible: [...document.querySelectorAll('[data-screenshot-exclude]')]
                        .every((element) => getComputedStyle(element).display !== 'none'),
                    buttonsEnabled: [...document.querySelectorAll('[data-statement-screenshot]')]
                        .every((element) => !element.disabled)
                });
            })()`,
            returnByValue: true,
            awaitPromise: true,
        },
    }));
});

socket.addEventListener('message', (event) => {
    const message = JSON.parse(event.data);
    if (message.id !== 1) return;
    clearTimeout(timeout);
    if (message.exceptionDetails) {
        console.error(JSON.stringify(message.exceptionDetails));
        process.exitCode = 1;
    } else {
        console.log(message.result.result.value);
    }
    socket.close();
});
