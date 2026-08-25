(() => {
  if (window.__studentDiscountPortalLoaded) return;
  window.__studentDiscountPortalLoaded = true;

  const moveReadyDialogs = () => {
    let waitingForInitialization = false;

    document.querySelectorAll("[data-student-discount-app]").forEach((app) => {
      if (app.dataset.initialized !== "true") {
        waitingForInitialization = true;
        return;
      }

      const dialog = app.querySelector("[data-student-discount-dialog]");
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

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start, { once: true });
  } else {
    start();
  }

  document.addEventListener("shopify:section:load", start);
})();
