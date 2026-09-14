"use strict";

(() => {
  const mounted = new WeakMap();
  const element = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = String(text);
    return node;
  };
  const label = (root, key, fallback) => root.dataset[key] || fallback;
  const safeUrl = (value) => {
    try {
      const url = new URL(value, location.origin);
      return url.protocol === "https:" || (url.origin === location.origin && url.protocol === "http:") ? url.href : "";
    } catch {
      return "";
    }
  };
  const stars = (root, rating, compact = false) => {
    const value = Math.max(0, Math.min(5, Number(rating) || 0));
    const node = element("span", compact ? "dr-stars dr-stars--compact" : "dr-stars");
    node.setAttribute("role", "img");
    node.setAttribute("aria-label", `${value} ${label(root, "ratingLabel", "out of 5 stars")}`);
    for (let index = 1; index <= 5; index += 1) {
      const star = element("span", index <= Math.round(value) ? "" : "dr-star--empty", "★");
      star.setAttribute("aria-hidden", "true");
      node.append(star);
    }
    return node;
  };
  const disclosure = (root, review) => {
    const row = element("div", "dr-disclosures");
    if (review.verified_source === "order") row.append(element("span", "dr-badge", label(root, "verified", "Verified purchase")));
    else row.append(element("span", "dr-source", label(root, "unverified", "Source not verified")));
    if (review.incentivized) row.append(element("span", "dr-badge dr-badge--incentive", label(root, "incentivized", "Incentivized review")));
    return row;
  };
  const mediaButton = (root, media, review, index, openLightbox) => {
    const button = element("button", "dr-media");
    button.type = "button";
    button.dataset.mediaIndex = String(index);
    button.setAttribute("aria-label", `${label(root, "openMedia", "Open review media")} ${index + 1}`);
    const url = safeUrl(media.url);
    if (!url) return null;
    if (media.type === "video") {
      const video = element("video");
      video.src = url;
      video.muted = true;
      video.preload = "metadata";
      video.setAttribute("playsinline", "");
      button.append(video, element("span", "dr-play", "▶"));
    } else {
      const image = element("img");
      image.src = url;
      image.alt = review.product_title || review.title || label(root, "reviewMedia", "Review media");
      image.loading = "lazy";
      image.decoding = "async";
      button.append(image);
    }
    button.addEventListener("click", () => openLightbox(index, button));
    return button;
  };
  const card = (root, review, openLightbox) => {
    const article = element("article", "dr-card");
    article.dataset.reviewId = review.uuid || "";
    const media = Array.isArray(review.media) ? review.media : [];
    if (media.length) {
      const gallery = element("div", "dr-card__media");
      media.slice(0, 3).forEach((item, index) => {
        const button = mediaButton(root, item, review, index, openLightbox);
        if (button) gallery.append(button);
      });
      if (gallery.childElementCount) article.append(gallery);
    }
    const body = element("div", "dr-card__body");
    body.append(stars(root, review.rating));
    if (review.title) body.append(element("h3", "dr-card__title", review.title));
    body.append(element("p", "dr-card__text", review.body || ""));
    if (Array.isArray(review.answers) && review.answers.length) {
      const answers = element("dl", "dr-answers");
      review.answers.forEach((answer) => {
        if (!answer?.label || answer.value === undefined || answer.value === null) return;
        answers.append(element("dt", "", answer.label), element("dd", "", Array.isArray(answer.value) ? answer.value.join(", ") : answer.value));
      });
      if (answers.childElementCount) body.append(answers);
    }
    if (review.reply) {
      const reply = element("blockquote", "dr-reply");
      reply.append(element("strong", "", label(root, "reply", "Store reply")), element("p", "", review.reply));
      body.append(reply);
    }
    body.append(disclosure(root, review));
    const footer = element("footer", "dr-card__footer");
    footer.append(element("strong", "", review.author_name || label(root, "anonymous", "Anonymous")));
    if (review.product_title) footer.append(element("span", "", review.product_title));
    if (review.created_at) {
      const time = element("time", "", new Intl.DateTimeFormat(document.documentElement.lang || undefined, { dateStyle: "medium" }).format(new Date(review.created_at)));
      time.dateTime = review.created_at;
      footer.append(time);
    }
    body.append(footer);
    article.append(body);
    return article;
  };
  const lightbox = (root, reviews) => {
    const items = reviews.flatMap((review) => (Array.isArray(review.media) ? review.media : []).filter((media) => safeUrl(media.url)).map((media) => ({ media, review })));
    if (!items.length) return { open() {}, destroy() {} };
    const dialog = element("dialog", "dr-lightbox");
    const close = element("button", "dr-lightbox__close", "×");
    close.type = "button";
    close.setAttribute("aria-label", label(root, "close", "Close"));
    const previous = element("button", "dr-lightbox__previous", "‹");
    previous.type = "button";
    previous.setAttribute("aria-label", label(root, "previous", "Previous"));
    const next = element("button", "dr-lightbox__next", "›");
    next.type = "button";
    next.setAttribute("aria-label", label(root, "next", "Next"));
    const stage = element("div", "dr-lightbox__stage");
    dialog.append(close, previous, stage, next);
    root.append(dialog);
    let current = 0;
    let restoreFocus = null;
    const show = () => {
      stage.replaceChildren();
      const item = items[current];
      const url = safeUrl(item.media.url);
      const media = element(item.media.type === "video" ? "video" : "img");
      media.src = url;
      if (item.media.type === "video") {
        media.controls = true;
        media.autoplay = true;
        media.setAttribute("playsinline", "");
      } else {
        media.alt = item.review.product_title || item.review.title || label(root, "reviewMedia", "Review media");
      }
      media.addEventListener("error", () => stage.replaceChildren(element("p", "dr-media-error", label(root, "mediaUnavailable", "This review media is unavailable."))));
      stage.append(media);
      const caption = element("p", "dr-lightbox__caption", `${item.review.author_name || label(root, "anonymous", "Anonymous")} · ${current + 1}/${items.length}`);
      stage.append(caption);
    };
    const move = (direction) => { current = (current + direction + items.length) % items.length; show(); };
    close.addEventListener("click", () => dialog.close());
    previous.addEventListener("click", () => move(-1));
    next.addEventListener("click", () => move(1));
    dialog.addEventListener("click", (event) => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener("keydown", (event) => {
      if (event.key === "ArrowLeft") move(-1);
      else if (event.key === "ArrowRight") move(1);
      else if (event.key === "Escape") { event.stopPropagation(); dialog.close(); }
    });
    dialog.addEventListener("close", () => restoreFocus?.focus());
    return {
      open(reviewUuid, mediaIndex, trigger) {
        const selectedMedia = reviews.find((review) => review.uuid === reviewUuid)?.media?.[mediaIndex];
        const index = items.findIndex((item) => item.review.uuid === reviewUuid && item.media === selectedMedia);
        current = index >= 0 ? index : 0;
        restoreFocus = trigger;
        show();
        dialog.showModal();
        close.focus();
      },
      destroy() { dialog.remove(); },
    };
  };
  const renderSummary = (root, payload) => {
    const target = root.querySelector("[data-dr-summary]");
    if (!target) return;
    target.replaceChildren();
    const summary = payload.summary || {};
    target.append(stars(root, summary.average || 0), element("strong", "dr-average", Number(summary.average || 0).toFixed(1)), element("span", "", `${summary.count || 0} ${label(root, "reviews", "reviews")}`));
    const histogram = element("div", "dr-histogram");
    for (let rating = 5; rating >= 1; rating -= 1) {
      const count = Number(summary.distribution?.[rating] || 0);
      const row = element("div", "dr-histogram__row");
      row.append(element("span", "", `${rating} ★`));
      const meter = element("meter");
      meter.min = 0;
      meter.max = Math.max(1, Number(summary.count || 0));
      meter.value = count;
      meter.setAttribute("aria-label", `${rating} ${label(root, "starsLabel", "stars")}: ${count}`);
      row.append(meter, element("span", "", count));
      histogram.append(row);
    }
    target.append(histogram);
  };
  const renderPager = (root, meta, state, load) => {
    const target = root.querySelector("[data-dr-pagination]");
    if (!target) return;
    target.replaceChildren();
    const current = Number(meta?.current_page || state.page || 1);
    const last = Number(meta?.last_page || 1);
    const button = (text, page, disabled) => {
      const node = element("button", "dr-button", text);
      node.type = "button";
      node.disabled = disabled;
      node.addEventListener("click", () => { state.page = page; load(); });
      return node;
    };
    target.append(button(label(root, "previous", "Previous"), current - 1, current <= 1), element("span", "", `${current} / ${last}`), button(label(root, "next", "Next"), current + 1, current >= last));
  };
  const renderOrganicForm = (root, payload) => {
    root.decoReviewsPayload = payload;
    globalThis.DecoReviewsOrganic?.render(root, payload);
  };
  const mount = (root) => {
    const hasFeed = Boolean(root.dataset.feedUrl);
    if (mounted.has(root) || !hasFeed) return;
    const state = { page: 1, sort: "newest", rating: "", kind: root.dataset.kind || "product" };
    const list = root.querySelector("[data-dr-list]");
    const status = root.querySelector("[data-dr-status]");
    const pagination = root.querySelector("[data-dr-pagination]");
    const listeners = [];
    const on = (node, type, handler) => {
      node?.addEventListener(type, handler);
      if (node) listeners.push(() => node.removeEventListener(type, handler));
    };
    let controller = null;
    let viewer = null;
    const setStatus = (message) => {
      if (!status) return;
      status.replaceChildren(element("span", "", message));
    };
    const load = async () => {
      controller?.abort();
      controller = new AbortController();
      root.dataset.state = "loading";
      setStatus(label(root, "loading", "Loading reviews…"));
      list?.setAttribute("aria-busy", "true");
      try {
        const url = new URL(root.dataset.feedUrl, location.origin);
        if (url.origin !== location.origin) throw new Error("Cross-origin feed blocked");
        url.searchParams.set("kind", state.kind);
        url.searchParams.set("sort", state.sort);
        url.searchParams.set("page", String(state.page));
        if (state.rating) url.searchParams.set("rating", state.rating);
        const response = await fetch(url, { headers: { Accept: "application/json" }, cache: "no-store", signal: controller.signal });
        const payload = await response.json();
        if (!response.ok || !Array.isArray(payload.data)) throw new Error(payload.message || "Unavailable");
        const reviews = payload.data;
        root.style.setProperty("--dr-star", payload.settings?.star_color || root.dataset.starColor || "#7c3aed");
        root.dataset.layout = payload.settings?.layout || root.dataset.layout || "grid";
        root.dataset.corner = payload.settings?.corner_style || "rounded";
        const heading = root.querySelector("[data-dr-heading]");
        if (heading && payload.settings?.heading) heading.textContent = payload.settings.heading;
        renderSummary(root, payload);
        renderOrganicForm(root, payload);
        let rendered = reviews.length;
        if (list) {
          viewer?.destroy();
          list.replaceChildren();
          viewer = lightbox(root, reviews);
          if (root.dataset.mode === "gallery") {
            reviews.forEach((review) => (Array.isArray(review.media) ? review.media : []).forEach((media, index) => {
              const button = mediaButton(root, media, review, index, (mediaIndex, trigger) => {
                if (trigger.getAttribute("aria-current") === "true") viewer.open(review.uuid, mediaIndex, trigger);
              });
              if (button) { button.className += " dr-media--gallery"; list.append(button); }
            }));
          } else {
            reviews.filter((review) => root.dataset.mode !== "video" || review.media?.some((media) => media.type === "video" && safeUrl(media.url)))
              .forEach((review) => list.append(card(root, review, (mediaIndex, trigger) => viewer.open(review.uuid, mediaIndex, trigger))));
          }
          rendered = list.childElementCount;
        }
        renderPager(root, payload.meta, state, load);
        root.dataset.state = rendered ? "ready" : "empty";
        setStatus(rendered ? "" : label(root, root.dataset.mode === "gallery" ? "mediaEmpty" : "empty", "No reviews yet."));
        root.dispatchEvent(new CustomEvent("deco:reviews:render"));
      } catch (error) {
        if (error.name !== "AbortError") {
          viewer?.destroy();
          list?.replaceChildren();
          pagination?.replaceChildren();
          root.dataset.state = "error";
          setStatus(label(root, "error", "Reviews are unavailable right now."));
          root.dispatchEvent(new CustomEvent("deco:reviews:render"));
        }
      } finally {
        list?.removeAttribute("aria-busy");
      }
    };
    on(root.querySelector("[data-dr-sort]"), "change", (event) => { state.sort = event.target.value; state.page = 1; load(); });
    on(root.querySelector("[data-dr-rating]"), "change", (event) => { state.rating = event.target.value; state.page = 1; load(); });
    mounted.set(root, { destroy: () => {
      controller?.abort();
      viewer?.destroy();
      listeners.forEach((remove) => remove());
      mounted.delete(root);
    } });
    if (hasFeed) load();
  };
  const scan = (scope = document) => scope.querySelectorAll("[data-deco-reviews]").forEach(mount);
  document.readyState === "loading" ? document.addEventListener("DOMContentLoaded", () => scan(), { once: true }) : scan();
  document.addEventListener("shopify:section:load", (event) => scan(event.target));
  document.addEventListener("shopify:section:unload", (event) => event.target.querySelectorAll("[data-deco-reviews]").forEach((root) => mounted.get(root)?.destroy()));
})();
/* DECO_REVIEWS_FORM */
(() => {
  const mounted = new WeakSet();
  const label = (root, key, fallback) => root.dataset[key] || fallback;
  const mount = (root) => {
    const form = root.querySelector("[data-dr-form]");
    if (!form || mounted.has(form)) return;
    let submitting = false;
    let submitted = false;
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      if (submitting || submitted) return;
      const formStatus = form.querySelector("[data-dr-form-status]");
      const submit = form.querySelector("button[type=submit]");
      const missingGroup = [...form.querySelectorAll("[data-answer-multiple][data-required]")].find((group) => !group.querySelector("input:checked"));
      if (missingGroup) {
        const first = missingGroup.querySelector("input");
        first.setCustomValidity(label(root, "requiredAnswer", "Choose at least one answer."));
        first.reportValidity();
        missingGroup.addEventListener("change", () => first.setCustomValidity(""), { once: true });
        return;
      }
      if (root.dataset.formPreview === "true") {
        formStatus.textContent = label(root, "previewMessage", "Preview only. No review was submitted or saved.");
        return;
      }
      formStatus.textContent = label(root, "submitting", "Submitting…");
      submitting = true;
      submit.disabled = true;
      try {
        const files = [...(form.elements.namedItem("media[]")?.files || [])];
        const videos = files.filter((file) => file.type.startsWith("video/"));
        const photos = files.filter((file) => file.type.startsWith("image/"));
        if (files.length !== videos.length + photos.length || videos.length > 1 || (videos.length && files.length > 1) || photos.length > 5
          || (videos.length && root.dataset.allowVideo !== "true") || (photos.length && root.dataset.allowPhotos !== "true")) throw new Error(label(root, "mediaError", "Upload up to 5 photos or 1 video."));
        const response = await fetch(form.action, { method: "POST", headers: { Accept: "application/json", "X-CSRF-TOKEN": root.dataset.csrf || "" }, body: new FormData(form) });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error((payload.errors ? Object.values(payload.errors).flat().join(" ") : payload.message) || label(root, "validationError", "Please review the form and try again."));
        form.reset();
        submitted = true;
        formStatus.textContent = payload.message || label(root, "thanks", "Thank you. Your review was submitted for moderation.");
        formStatus.setAttribute("tabindex", "-1");
        formStatus.focus();
      } catch (error) {
        formStatus.textContent = error.message || label(root, "error", "Something went wrong.");
      } finally {
        submitting = false;
        submit.disabled = submitted;
      }
    });
    mounted.add(form);
  };
  const scan = (scope = document) => scope.querySelectorAll("[data-deco-reviews]").forEach(mount);
  document.readyState === "loading" ? document.addEventListener("DOMContentLoaded", () => scan(), { once: true }) : scan();
  document.addEventListener("shopify:section:load", (event) => scan(event.target));
})();
/* DECO_REVIEWS_WIDGETS */
(() => {
  if (globalThis.DecoReviewsWidgetsLoaded) return;
  globalThis.DecoReviewsWidgetsLoaded = true;
  const mounted = new WeakMap();
  const closed = new Set();
  const reduce = () => globalThis.matchMedia?.("(prefers-reduced-motion: reduce)").matches;
  const mount = (root) => {
    if (mounted.has(root)) return;
    const mode = root.dataset.mode;
    const list = root.querySelector("[data-dr-list]");
    const controls = root.querySelector("[data-dr-carousel-controls]");
    const previous = root.querySelector("[data-dr-carousel-previous]");
    const next = root.querySelector("[data-dr-carousel-next]");
    const position = root.querySelector("[data-dr-carousel-status]");
    const galleryStatus = root.querySelector("[data-dr-gallery-status]");
    const cleanup = [];
    const on = (node, type, handler) => {
      node?.addEventListener(type, handler);
      if (node) cleanup.push(() => node.removeEventListener(type, handler));
    };
    let index = 0;
    let manual = false;
    let hovered = false;
    let focused = false;
    let timer = null;
    const stop = () => { manual = true; globalThis.clearInterval?.(timer); };
    const items = () => list ? [...list.children] : [];
    const text = (count) => (root.dataset.itemPosition || "Item {current} of {total}").replace("{current}", index + 1).replace("{total}", count);
    const refresh = () => {
      const nodes = items();
      index = Math.max(0, Math.min(index, nodes.length - 1));
      if (["carousel", "video"].includes(mode)) {
        controls.hidden = !nodes.length;
        if (!nodes.length) return list.removeAttribute("tabindex");
        list.setAttribute("tabindex", "0");
        list.setAttribute("role", "region");
        list.setAttribute("aria-roledescription", "carousel");
        nodes.forEach((node) => node.setAttribute("tabindex", "-1"));
        previous.disabled = index === 0;
        next.disabled = index === nodes.length - 1;
        position.textContent = text(nodes.length);
      } else if (mode === "gallery") {
        list.setAttribute("role", "listbox");
        nodes.forEach((node, item) => {
          node.setAttribute("role", "option");
          node.setAttribute("tabindex", item === index ? "0" : "-1");
          node.setAttribute("aria-selected", String(item === index));
          item === index ? node.setAttribute("aria-current", "true") : node.removeAttribute("aria-current");
        });
        if (galleryStatus) {
          galleryStatus.hidden = !nodes.length;
          galleryStatus.textContent = nodes.length ? text(nodes.length) : "";
        }
      }
    };
    const move = (step, user = true, absolute = false) => {
      const nodes = items();
      if (!nodes.length) return;
      if (user) stop();
      index = absolute ? step : user ? Math.max(0, Math.min(index + step, nodes.length - 1)) : (index + step) % nodes.length;
      const item = nodes[index];
      item.scrollIntoView({ behavior: reduce() ? "auto" : "smooth", block: "nearest", inline: "start" });
      if (user) item.focus({ preventScroll: true });
      refresh();
    };
    if (["carousel", "video"].includes(mode)) {
      on(previous, "click", () => move(-1));
      on(next, "click", () => move(1));
      on(root, "mouseenter", () => { hovered = true; });
      on(root, "mouseleave", () => { hovered = false; });
      on(root, "focusin", () => { focused = true; });
      on(root, "focusout", (event) => { if (!root.contains(event.relatedTarget)) focused = false; });
      on(list, "pointerdown", stop);
      if (mode === "carousel" && root.dataset.autoplay === "true" && !reduce()) timer = globalThis.setInterval?.(() => {
        if (!manual && !hovered && !focused && !document.hidden && !reduce()) move(1, false);
      }, Math.max(3, Number(root.dataset.autoplaySeconds) || 5) * 1000);
    }
    on(list, "keydown", (event) => {
      if (!((["carousel", "video"].includes(mode) && ["ArrowLeft", "ArrowRight"].includes(event.key)) || (mode === "gallery" && ["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)))) return;
      event.preventDefault();
      if (event.key === "Home") move(0, true, true);
      else if (event.key === "End") move(items().length - 1, true, true);
      else move(event.key === "ArrowLeft" ? -1 : 1);
    });
    if (mode === "gallery") on(list, "click", (event) => {
      const item = items().findIndex((node) => node === event.target || node.contains(event.target));
      if (item >= 0) { index = item; refresh(); }
    });
    const open = root.querySelector("[data-dr-open]");
    const close = root.querySelector("[data-dr-close]");
    const panel = root.querySelector("[data-dr-panel]");
    const collapsible = ["sidebar", "floating"].includes(mode);
    const key = root.dataset.blockId || root.id;
    const setPanel = (visible, restore = false) => {
      if (!collapsible) return;
      panel.hidden = !visible;
      open.hidden = visible;
      open.setAttribute("aria-expanded", String(visible));
      root.dataset.open = String(visible);
      if (visible) { closed.delete(key); close.focus(); }
      else { if (restore) { closed.add(key); open.focus(); } }
    };
    if (collapsible) {
      close.hidden = false;
      setPanel(root.dataset.open === "true" && !closed.has(key));
      on(open, "click", () => setPanel(true));
      on(close, "click", () => setPanel(false, true));
      on(root, "keydown", (event) => { if (event.key === "Escape" && root.dataset.open === "true") setPanel(false, true); });
    }
    on(root, "deco:reviews:render", () => { index = 0; refresh(); });
    refresh();
    mounted.set(root, { destroy() { globalThis.clearInterval?.(timer); cleanup.forEach((remove) => remove()); mounted.delete(root); } });
  };
  const scan = (scope = document) => scope.querySelectorAll("[data-deco-reviews]").forEach(mount);
  document.readyState === "loading" ? document.addEventListener("DOMContentLoaded", () => scan(), { once: true }) : scan();
  document.addEventListener("shopify:section:load", (event) => scan(event.target));
  document.addEventListener("shopify:section:unload", (event) => event.target.querySelectorAll("[data-deco-reviews]").forEach((root) => mounted.get(root)?.destroy()));
})();
