const IVAC_DG_EPAY_CALLBACK_PREFIX =
    "https://api.ivacbd.com/iams/api/v1/payment/dg-epay/callback";

const API_URL =
    "https://ipms.senda.fit/api/payment-links/redirect-url";

const handledUrls = new Set();

chrome.runtime.onInstalled.addListener(() => {
    console.log("[DURONTO IVAC Helper] Extension installed.");
});

// Lets the DURONTO portal landing page detect that the extension is installed and
// which version, so it can show "Installed / Update available" instead of "Add".
chrome.runtime.onMessageExternal.addListener((message, sender, sendResponse) => {
    if (message && message.type === "DURONTO_PAYMENT_HELPER_PING") {
        sendResponse({
            installed: true,
            version: chrome.runtime.getManifest().version
        });
    }
    return true;
});

chrome.webNavigation.onBeforeNavigate.addListener((details) => {
    handleCallback(details.tabId, details.url);
});

chrome.tabs.onUpdated.addListener((tabId, changeInfo, tab) => {
    handleCallback(tabId, changeInfo.url || tab.url || "");
});

async function handleCallback(tabId, url) {
    if (!url.startsWith(IVAC_DG_EPAY_CALLBACK_PREFIX)) {
        return;
    }

    if (handledUrls.has(url)) {
        return;
    }

    handledUrls.add(url);
    setTimeout(() => handledUrls.delete(url), 60000);

    try {
        // Save redirect URL
        await fetch(API_URL, {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                url: url
            })
        });

        // Redirect browser to GET endpoint
        chrome.tabs.update(tabId, {
            url: `${API_URL}?url=${encodeURIComponent(url)}`
        });

    } catch (err) {
        console.error("[DURONTO IVAC Helper] Failed:", err);
    }
}
