/* Instagram 视频 app block：轮播/网格 + 静音自动播放 + 放大播放 */
(function () {
  "use strict";

  var lightbox = null;
  var lightboxState = { items: [], index: 0, opener: null, root: null };

  /* ------------------------- 工具 ------------------------- */

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

  function readItems(root) {
    var script = root.querySelector("[data-igv-json]");
    if (!script) return [];
    try {
      var parsed = JSON.parse(script.textContent);
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  }

  function pauseAll(root) {
    root.querySelectorAll("[data-igv-video]").forEach(function (video) {
      video.pause();
    });
  }

  /* ------------------- 静音自动播放 ------------------- */

  function setupAutoplay(root) {
    if (root.dataset.autoplay !== "true") return;
    var videos = root.querySelectorAll("[data-igv-video]");
    if (!videos.length) return;

    if (!("IntersectionObserver" in window)) {
      videos.forEach(function (video) {
        video.setAttribute("controls", "");
      });
      return;
    }

    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          var video = entry.target;
          if (entry.isIntersecting) {
            video.muted = true;
            var playing = video.play();
            if (playing && typeof playing.catch === "function") {
              // 浏览器拒绝自动播放时退回到手动控制
              playing.catch(function () {
                video.setAttribute("controls", "");
              });
            }
          } else {
            video.pause();
          }
        });
      },
      { threshold: 0.4 },
    );

    videos.forEach(function (video) {
      observer.observe(video);
    });
  }

  /* ----------------------- 轮播 ----------------------- */

  function setupCarousel(root) {
    if (root.dataset.layout !== "carousel") return;

    var list = root.querySelector("[data-igv-list]");
    if (!list) return;

    var prev = root.querySelector("[data-igv-prev]");
    var next = root.querySelector("[data-igv-next]");

    function stepSize() {
      var item = list.querySelector(".igv__item");
      if (!item) return list.clientWidth;
      var styles = window.getComputedStyle(list);
      var gap = parseFloat(styles.columnGap || styles.gap || "0") || 0;
      return item.getBoundingClientRect().width + gap;
    }

    var update = throttle(function () {
      var maxScroll = list.scrollWidth - list.clientWidth - 2;
      if (prev) prev.hidden = list.scrollLeft <= 2;
      if (next) next.hidden = list.scrollLeft >= maxScroll;
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
    window.addEventListener("resize", update);
    update();
  }

  /* --------------------- 放大播放 --------------------- */

  function buildLightbox() {
    if (lightbox) return lightbox;

    lightbox = document.createElement("div");
    lightbox.className = "igv-lightbox";
    lightbox.hidden = true;
    lightbox.setAttribute("role", "dialog");
    lightbox.setAttribute("aria-modal", "true");
    lightbox.setAttribute("aria-label", "Instagram 视频");

    lightbox.innerHTML = [
      '<div class="igv-lightbox__dialog">',
      '  <button class="igv-lightbox__button igv-lightbox__close" type="button" aria-label="关闭">&times;</button>',
      '  <button class="igv-lightbox__button igv-lightbox__prev" type="button" aria-label="上一个">&#8249;</button>',
      '  <button class="igv-lightbox__button igv-lightbox__next" type="button" aria-label="下一个">&#8250;</button>',
      '  <video class="igv-lightbox__video" controls playsinline></video>',
      '  <div class="igv-lightbox__meta">',
      '    <p class="igv-lightbox__caption"></p>',
      '    <div class="igv-lightbox__links"></div>',
      "  </div>",
      "</div>",
    ].join("");

    lightbox
      .querySelector(".igv-lightbox__close")
      .addEventListener("click", closeLightbox);
    lightbox
      .querySelector(".igv-lightbox__prev")
      .addEventListener("click", function () {
        showLightboxItem(lightboxState.index - 1);
      });
    lightbox
      .querySelector(".igv-lightbox__next")
      .addEventListener("click", function () {
        showLightboxItem(lightboxState.index + 1);
      });

    lightbox.addEventListener("click", function (event) {
      if (event.target === lightbox) closeLightbox();
    });

    document.addEventListener("keydown", function (event) {
      if (lightbox.hidden) return;
      if (event.key === "Escape") closeLightbox();
      if (event.key === "ArrowLeft") showLightboxItem(lightboxState.index - 1);
      if (event.key === "ArrowRight") showLightboxItem(lightboxState.index + 1);
    });

    document.body.appendChild(lightbox);
    return lightbox;
  }

  function showLightboxItem(index) {
    var items = lightboxState.items;
    if (!items.length) return;

    var total = items.length;
    var nextIndex = ((index % total) + total) % total;
    lightboxState.index = nextIndex;

    var item = items[nextIndex];
    var video = lightbox.querySelector(".igv-lightbox__video");
    var caption = lightbox.querySelector(".igv-lightbox__caption");
    var links = lightbox.querySelector(".igv-lightbox__links");

    video.pause();
    video.removeAttribute("src");
    if (item.poster_url) {
      video.setAttribute("poster", item.poster_url);
    } else {
      video.removeAttribute("poster");
    }

    if (item.video_url) {
      video.src = item.video_url;
      video.muted = false;
      video.load();
      var playing = video.play();
      if (playing && typeof playing.catch === "function") {
        playing.catch(function () {});
      }
    }

    caption.textContent = item.caption || "";
    caption.hidden = !item.caption;

    links.textContent = "";
    (item.products || []).forEach(function (product) {
      if (!product || !product.url) return;
      var link = document.createElement("a");
      link.className = "igv-lightbox__link";
      link.href = product.url;
      link.textContent = product.title || "查看商品";
      links.appendChild(link);
    });

    if (item.permalink) {
      var igLink = document.createElement("a");
      igLink.className = "igv-lightbox__link";
      igLink.href = item.permalink;
      igLink.target = "_blank";
      igLink.rel = "noopener nofollow";
      igLink.textContent = "在 Instagram 查看";
      links.appendChild(igLink);
    }

    var multiple = total > 1;
    lightbox.querySelector(".igv-lightbox__prev").hidden = !multiple;
    lightbox.querySelector(".igv-lightbox__next").hidden = !multiple;
  }

  function openLightbox(items, index, opener, root) {
    if (!items.length) return;

    buildLightbox();
    lightboxState.items = items;
    lightboxState.opener = opener;
    lightboxState.root = root;

    if (root) pauseAll(root);

    lightbox.hidden = false;
    document.documentElement.style.overflow = "hidden";
    showLightboxItem(index);
    lightbox.querySelector(".igv-lightbox__close").focus();
  }

  function closeLightbox() {
    if (!lightbox || lightbox.hidden) return;

    var video = lightbox.querySelector(".igv-lightbox__video");
    video.pause();
    video.removeAttribute("src");
    video.load();

    lightbox.hidden = true;
    document.documentElement.style.overflow = "";

    if (lightboxState.opener && lightboxState.opener.focus) {
      lightboxState.opener.focus();
    }
    lightboxState.opener = null;
  }

  function setupLightbox(root) {
    if (root.dataset.lightbox !== "true") return;

    var items = readItems(root);
    if (!items.length) return;

    root.querySelectorAll("[data-igv-open]").forEach(function (trigger) {
      trigger.addEventListener("click", function () {
        var index = parseInt(trigger.getAttribute("data-igv-open"), 10);
        openLightbox(items, isNaN(index) ? 0 : index, trigger, root);
      });
    });
  }

  /* ---------------------- 初始化 ---------------------- */

  function init(root) {
    if (!root || root.dataset.igvReady === "true") return;
    root.dataset.igvReady = "true";

    setupAutoplay(root);
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

  // 主题编辑器里增删/移动区块后重新初始化
  document.addEventListener("shopify:section:load", function (event) {
    initAll(event.target);
  });
  document.addEventListener("shopify:block:select", function (event) {
    initAll(event.target);
  });
})();
