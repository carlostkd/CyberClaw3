const { chromium } = require('playwright');

const LUMO_URL = 'https://carlostkd.ch/lumo/lumo.php';

async function callLumo(prompt) {
    const body = new URLSearchParams({ prompt });

    const response = await fetch(LUMO_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'User-Agent': 'Mozilla/5.0 (compatible; ThreemaBot/1.0)',
        },
        body: body.toString(),
    });

    if (!response.ok) {
        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }

    return (await response.text()).trim();
}

(async () => {
    const browser = await chromium.launch({ headless: false });
    const context = await browser.newContext();
    const page    = await context.newPage();

    await page.goto('https://web.threema.ch');
    await page.waitForSelector('li[id^="message-"]', { timeout: 120000 });

    await page.exposeFunction('forwardToLumo', async (messageId, prompt) => {
        try {
            const reply = await callLumo(prompt);

            await page.evaluate(reply => {
                const input = document.getElementById('composeDiv');
                if (!input) return;
                input.focus();
                input.innerHTML = '';
                const node = document.createTextNode(reply + ' ');
                input.appendChild(node);
                const range = document.createRange();
                range.setStart(node, node.length);
                range.collapse(true);
                const sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
                input.dispatchEvent(new InputEvent('input', { bubbles: true }));
                input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
                const sendBtn = document.querySelector('i.send-trigger.is-enabled');
                if (sendBtn) sendBtn.click();
            }, reply);

        } catch (err) {}
    });

    await page.evaluate(() => {
        const messageItems = Array.from(document.querySelectorAll('li[id^="message-"]'));
        if (!messageItems.length) return;
        const container = messageItems[0].parentElement;

        const repliedMessages = new Set();

        const observer = new MutationObserver(mutations => {
            mutations.forEach(m => {
                m.addedNodes.forEach(node => {
                    if (node.tagName !== 'LI' || !node.id || repliedMessages.has(node.id)) return;
                    const textSpan = node.querySelector('eee-message-text span');
                    if (!textSpan) return;
                    const text       = textSpan.innerText.trim();
                    const article    = node.querySelector('article');
                    const isIncoming = article?.classList.contains('message-in');
                    if (isIncoming && text.toLowerCase().startsWith('sudo')) {
                        window.forwardToLumo(node.id, text);
                    }
                    repliedMessages.add(node.id);
                });
            });
        });

        observer.observe(container, { childList: true });
    });
})();
