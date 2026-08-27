(() => {
  if (window.__studentDiscountPortalV2Loaded) return;
  window.__studentDiscountPortalV2Loaded = true;

  const removeLegacyInstances = () => {
    document
      .querySelectorAll('[data-student-discount-app][data-endpoint]:not([data-proxy-path])')
      .forEach((app) => app.remove());

    document
      .querySelectorAll('[data-student-discount-dialog]:not([data-student-discount-version="2"])')
      .forEach((dialog) => dialog.remove());
  };

  const moveReadyDialogs = () => {
    removeLegacyInstances();
    let waitingForInitialization = false;

    document.querySelectorAll('[data-student-discount-app][data-student-discount-version="2"]').forEach((app) => {
      if (app.dataset.initialized !== 'true') {
        waitingForInitialization = true;
        return;
      }

      const dialog = app.querySelector('[data-student-discount-dialog]');
      if (dialog && dialog.parentElement !== document.body) {
        document.body.appendChild(dialog);
      }
    });

    return waitingForInitialization;
  };

  const start = () => {
    let attempts = 0;

    const retry = () => {
      const waiting = moveReadyDialogs();
      attempts += 1;

      if (waiting && attempts < 40) {
        window.setTimeout(retry, 50);
      }
    };

    window.setTimeout(retry, 0);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, {once: true});
  } else {
    start();
  }

  document.addEventListener('shopify:section:load', start);
})();
