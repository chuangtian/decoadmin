/*
 * Instagram content app block: carousel + popup with the original post.
 *
 * Covers only. Instagram withholds the video file URL for Reels with licensed
 * audio or downloads turned off, so the popup embeds Instagram's own player.
 * Skeleton comes from the liquid <template>; this file just clones it.
 * Shopify caps this file at 10KB - measure before adding code. English only.
 */
(function () {
  "use strict";

  var IG_ORIGIN = "https://www.instagram.com";
  var DATE_OPTS = { year: "numeric", month: "short", day: "numeric" };
  var box = null;
  var state = { items: [], index: 0, opener: null };

  function throttle(fn) {
    var scheduled = false;
    return function () {
      if (scheduled) return;
      scheduled = true;
      window.requestAnimationFrame(function () {
        scheduled = false;
        fn();
      });
    };
  }

  function pick(scope, name) {
    return scope.querySelector("[data-igv-" + name + "]");
  }

  /* --- Carousel --- */

  function setupCarousel(root) {
    var list = pick(root, "list");
    if (!list) return;

    var prev = pick(root, "prev");
    var next = pick(root, "next");
    var dotsBox = pick(root, "dots");
    var pageCount = 0;

    // One post per click (gap included): a full-page jump skips too much.
    function stepSize() {
      var item = list.querySelector(".igv__item");
      if (!item) return list.clientWidth;
      var styles = window.getComputedStyle(list);
      var gap = parseFloat(styles.columnGap || styles.gap || "0") || 0;
      return item.getBoundingClientRect().width + gap;
    }

    // Dots count pages, not posts: columns come from a CSS variable.
    function pages() {
      if (list.clientWidth <= 0) return 1;
      return Math.max(1, Math.ceil(list.scrollWidth / list.clientWidth));
    }

    function activePage() {
      if (list.clientWidth <= 0) return 0;
      var i = Math.round(list.scrollLeft / list.clientWidth);
      return Math.min(Math.max(i, 0), pages() - 1);
    }

    function onDotClick(event) {
      var page = parseInt(event.currentTarget.dataset.page, 10) || 0;
      list.scrollTo({ left: page * list.clientWidth, behavior: "smooth" });
    }

    function buildDots() {
      if (!dotsBox) return;
      pageCount = pages();
      dotsBox.textContent = "";
      if (pageCount < 2) return;

      for (var i = 0; i < pageCount; i++) {
        var dot = document.createElement("button");
        dot.type = "button";
        dot.className = "igv__dot";
        // aria-current, not role="tab": a tab role needs a matching tabpanel.
        dot.setAttribute("aria-label", "Page " + (i + 1));
        dot.dataset.page = String(i);
        dot.addEventListener("click", onDotClick);
        dotsBox.appendChild(dot);
      }
    }

    function syncDots() {
      if (!dotsBox || pageCount < 2) return;
      var current = activePage();
      var dots = dotsBox.children;
      for (var i = 0; i < dots.length; i++) {
        // Never disable the current dot: that removes it from the tab order.
        if (i === current) dots[i].setAttribute("aria-current", "true");
        else dots[i].removeAttribute("aria-current");
      }
    }

    var update = throttle(function () {
      var max = list.scrollWidth - list.clientWidth - 2;
      // Disabled, not hidden: hiding makes the opposite arrow jump.
      if (prev) prev.disabled = list.scrollLeft <= 2;
      if (next) next.disabled = list.scrollLeft >= max;
      syncDots();
    });

    // A viewport change alters posts per view, so the dots must be rebuilt.
    var rebuild = throttle(function () {
      if (pages() !== pageCount) buildDots();
      update();
    });

    if (prev) {
      prev.addEventListener("click", function () {
        list.scrollBy({ left: -stepSize(), behavior: "smooth" });
      });
    }
    if (next) {
      next.addEventListener("click", function () {
        list.scrollBy({ left: stepSize(), behavior: "smooth" });
      });
    }

    list.addEventListener("scroll", update);
    window.addEventListener("resize", rebuild);
    // Images are lazy loaded; scrollWidth is only final once they are in.
    window.addEventListener("load", rebuild);

    buildDots();
    update();
  }

  /* --- Post popup --- */

  function mount(root) {
    if (box) return box;

    var template = pick(root, "lightbox-template");
    if (!template || !template.content) return null;

    var node = template.content.firstElementChild.cloneNode(true);
    document.body.appendChild(node);
    box = {
      root: node,
      frame: pick(node, "embed"),
      loading: pick(node, "loading"),
      date: pick(node, "date"),
      caption: pick(node, "caption"),
      links: pick(node, "links"),
      prev: pick(node, "lb-prev"),
      next: pick(node, "lb-next"),
    };

    pick(node, "close").addEventListener("click", close);
    box.prev.addEventListener("click", function () {
      show(state.index - 1);
    });
    box.next.addEventListener("click", function () {
      show(state.index + 1);
    });
    box.frame.addEventListener("load", function () {
      box.loading.hidden = true;
    });
    node.addEventListener("click", function (event) {
      if (event.target === node) close();
    });
    document.addEventListener("keydown", function (event) {
      if (node.hidden) return;
      if (event.key === "Escape") close();
      if (event.key === "ArrowLeft") show(state.index - 1);
      if (event.key === "ArrowRight") show(state.index + 1);
    });

    // The embed is cross-origin: its height can only come from the message it
    // posts to us. Without it the frame keeps a guessed height and clips.
    window.addEventListener("message", function (event) {
      if (event.origin !== IG_ORIGIN || box.root.hidden) return;
      var data = event.data;
      if (typeof data === "string") {
        try {
          data = JSON.parse(data);
        } catch (error) {
          return;
        }
      }
      var height = data && data.details && data.details.height;
      if (height > 0) box.frame.parentNode.style.height = height + "px";
    });

    return box;
  }

  function link(href, text, external) {
    var node = document.createElement("a");
    node.className = "igv-lightbox__link";
    node.href = href;
    node.textContent = text;
    if (external) {
      node.target = "_blank";
      node.rel = "noopener nofollow";
    }
    return node;
  }

  function show(index) {
    var items = state.items;
    if (!items.length) return;

    var total = items.length;
    state.index = ((index % total) + total) % total;
    var item = items[state.index];

    // Uncaptioned embed on purpose: the caption lives in the right column, and
    // the captioned variant would repeat it inside the frame.
    var src = (item.embed_url || "").replace("/embed/captioned", "/embed/");
    var frameBox = box.frame.parentNode;
    frameBox.style.height = "";
    frameBox.hidden = !src;
    if (src) {
      box.loading.hidden = false;
      box.frame.src = src;
    } else {
      box.frame.removeAttribute("src");
    }

    var posted = item.posted_at ? new Date(item.posted_at) : null;
    var dated = posted && !isNaN(posted.getTime());
    // Locale pinned to en-US so the popup never picks up a translated month.
    box.date.textContent = dated ? posted.toLocaleDateString("en-US", DATE_OPTS) : "";
    box.date.hidden = !dated;

    box.caption.textContent = item.caption || "";
    box.caption.hidden = !item.caption;

    box.links.textContent = "";
    (item.products || []).forEach(function (product) {
      if (product && product.url) {
        box.links.appendChild(link(product.url, product.title || "View product"));
      }
    });
    if (item.permalink) {
      box.links.appendChild(link(item.permalink, "View on Instagram", true));
    }

    box.prev.hidden = total < 2;
    box.next.hidden = total < 2;
  }

  function close() {
    if (!box || box.root.hidden) return;

    // Clearing src matters: otherwise Instagram's iframe keeps running in the
    // background, which is obvious with a video that has audio.
    box.frame.removeAttribute("src");
    box.root.hidden = true;
    document.documentElement.style.overflow = "";

    if (state.opener && state.opener.focus) state.opener.focus();
    state.opener = null;
  }

  function setupLightbox(root) {
    // In link mode the cover is already a server-rendered <a>; JS stays out.
    if (root.dataset.clickAction !== "modal") return;

    var script = pick(root, "json");
    if (!script) return;

    var items;
    try {
      items = JSON.parse(script.textContent);
    } catch (error) {
      return;
    }
    if (!Array.isArray(items) || !items.length) return;
    if (!mount(root)) return;

    root.querySelectorAll("[data-igv-open]").forEach(function (trigger) {
      trigger.addEventListener("click", function () {
        var index = parseInt(trigger.getAttribute("data-igv-open"), 10);
        state.items = items;
        state.opener = trigger;
        box.root.hidden = false;
        document.documentElement.style.overflow = "hidden";
        show(isNaN(index) ? 0 : index);
        pick(box.root, "close").focus();
      });
    });
  }

  /* --- Bootstrap --- */

  function init(root) {
    if (!root || root.dataset.igvReady === "true") return;
    root.dataset.igvReady = "true";

    setupCarousel(root);
    setupLightbox(root);
  }

  function initAll(scope) {
    (scope || document).querySelectorAll("[data-igv]").forEach(init);
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", function () {
      initAll();
    });
  } else {
    initAll();
  }

  // Re-init after blocks are added, moved or selected in the theme editor.
  document.addEventListener("shopify:section:load", function (event) {
    initAll(event.target);
  });
  document.addEventListener("shopify:block:select", function (event) {
    initAll(event.target);
  });
})();
