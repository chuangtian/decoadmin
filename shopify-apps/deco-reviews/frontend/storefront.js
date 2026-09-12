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
    const items = reviews.flatMap((review) => (Array.isArray(review.media) ? review.media : []).map((media) => ({ media, review })));
    if (!items.length) return { open() {} };
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
      if (item.media.type === "video") {
        const video = element("video");
        video.src = url;
        video.controls = true;
        video.autoplay = true;
        video.setAttribute("playsinline", "");
        stage.append(video);
      } else {
        const image = element("img");
        image.src = url;
        image.alt = item.review.product_title || item.review.title || label(root, "reviewMedia", "Review media");
        stage.append(image);
      }
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
      if (event.key === "ArrowRight") move(1);
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
  const mount = (root) => {
    const form = root.querySelector("[data-dr-form]");
    const hasFeed = Boolean(root.dataset.feedUrl);
    if (mounted.has(root) || (!hasFeed && !form)) return;
    const state = { page: 1, sort: "newest", rating: "", kind: root.dataset.kind || "product" };
    const list = root.querySelector("[data-dr-list]");
    const status = root.querySelector("[data-dr-status]");
    let controller = null;
    let currentReviews = [];
    let viewer = null;
    const load = async () => {
      controller?.abort();
      controller = new AbortController();
      if (status) status.textContent = label(root, "loading", "Loading reviews…");
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
        currentReviews = payload.data;
        root.style.setProperty("--dr-star", payload.settings?.star_color || root.dataset.starColor || "#7c3aed");
        root.dataset.layout = payload.settings?.layout || root.dataset.layout || "grid";
        root.dataset.corner = payload.settings?.corner_style || "rounded";
        const heading = root.querySelector("[data-dr-heading]");
        if (heading && payload.settings?.heading) heading.textContent = payload.settings.heading;
        renderSummary(root, payload);
        if (list) {
          list.replaceChildren();
          viewer = lightbox(root, currentReviews);
          currentReviews.forEach((review) => list.append(card(root, review, (mediaIndex, trigger) => viewer.open(review.uuid, mediaIndex, trigger))));
        }
        renderPager(root, payload.meta, state, load);
        if (status) status.textContent = currentReviews.length ? "" : label(root, "empty", "No reviews yet.");
      } catch (error) {
        if (error.name !== "AbortError" && status) status.textContent = label(root, "error", "Reviews are unavailable right now.");
      } finally {
        list?.removeAttribute("aria-busy");
      }
    };
    root.querySelector("[data-dr-sort]")?.addEventListener("change", (event) => { state.sort = event.target.value; state.page = 1; load(); });
    root.querySelector("[data-dr-rating]")?.addEventListener("change", (event) => { state.rating = event.target.value; state.page = 1; load(); });
    form?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const formStatus = form.querySelector("[data-dr-form-status]");
      const submit = form.querySelector("button[type=submit]");
      const missingGroup = [...form.querySelectorAll("[data-answer-multiple][data-required]")]
        .find((group) => !group.querySelector("input:checked"));
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
      submit.disabled = true;
      try {
        const files = [...(form.elements.namedItem("media[]")?.files || [])];
        const videos = files.filter((file) => file.type.startsWith("video/"));
        const photos = files.filter((file) => file.type.startsWith("image/"));
        if (files.length !== videos.length + photos.length || videos.length > 1 || (videos.length && files.length > 1) || photos.length > 5
          || (videos.length && root.dataset.allowVideo !== "true") || (photos.length && root.dataset.allowPhotos !== "true")) {
          throw new Error(label(root, "mediaError", "Upload up to 5 photos or 1 video."));
        }
        const response = await fetch(form.action, { method: "POST", headers: { Accept: "application/json", "X-CSRF-TOKEN": root.dataset.csrf || "" }, body: new FormData(form) });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) {
          const errors = payload.errors ? Object.values(payload.errors).flat().join(" ") : payload.message;
          throw new Error(errors || label(root, "validationError", "Please review the form and try again."));
        }
        form.reset();
        formStatus.textContent = label(root, "thanks", "Thank you. Your review was submitted for moderation.");
      } catch (error) {
        formStatus.textContent = error.message || label(root, "error", "Something went wrong.");
      } finally {
        submit.disabled = false;
      }
    });
    mounted.set(root, { destroy: () => controller?.abort() });
    if (hasFeed) load();
  };
  const scan = (scope = document) => scope.querySelectorAll("[data-deco-reviews]").forEach(mount);
  document.readyState === "loading" ? document.addEventListener("DOMContentLoaded", () => scan(), { once: true }) : scan();
  document.addEventListener("shopify:section:load", (event) => scan(event.target));
  document.addEventListener("shopify:section:unload", (event) => event.target.querySelectorAll("[data-deco-reviews]").forEach((root) => mounted.get(root)?.destroy()));
})();
