(function () {
    'use strict';

    function refreshReadingCount() {
        if (!window.zenReadingCount || Number(window.zenReadingCount.enabled) !== 1) return;

        const counter = document.getElementById('zen-reading-count');
        if (!counter) return;

        const postId = counter.getAttribute('data-post-id');
        if (!postId || !/^[1-9]\d*$/.test(postId)) return;

        const body = new URLSearchParams({ action: 'zen_post_view', post_id: postId });
        fetch(window.zenReadingCount.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: body,
        }).then(function (response) {
            if (!response.ok) return null;
            return response.json();
        }).then(function (result) {
            if (!result || result.success !== true || !result.data) return;
            const count = result.data.count;
            if (typeof count !== 'string' && (!Number.isSafeInteger(count) || count < 0)) return;
            if (count === '') return;
            counter.textContent = String(count);
        }).catch(function () {});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshReadingCount, { once: true });
    } else {
        refreshReadingCount();
    }
})();
