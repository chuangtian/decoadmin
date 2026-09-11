/*
 * Instagram 内容 app block：轮播/网格 + 点击弹窗看原帖。
 *
 * 只展示转存到 R2 的封面图，不自己播视频 —— Instagram 出于版权保护，对部分 Reels
 * （用了平台授权音乐、或关闭了「允许下载」）根本不返回视频文件地址。所以点击封面
 * 打开弹窗，里面嵌 Instagram 官方 embed：视频与轮播都由 Instagram 自己渲染，
 * 内容完整、也不涉及版权问题，且不需要任何令牌。
 *
 * iframe 是跨域的，读不到内部高度，所以弹窗用固定宽高比容器并允许内部滚动。
 */
(function () {
  "use strict";

  var lightbox = null;
  var lightboxState = { items: [], index: 0, opener: null };

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

  /* ----------------------- 轮播 ----------------------- */

  function setupCarousel(root) {
    if (root.dataset.layout !== "carousel") return;

    var list = root.querySelector("[data-igv-list]");
    if (!list) return;

    var prev = root.querySelector("[data-igv-prev]");
    var next = root.querySelector("[data-igv-next]");
    var dotsBox = root.querySelector("[data-igv-dots]");
    var pageCount = 0;

    // 一次翻一整条（含间距），而不是一整屏：桌面端一屏 4 条时整屏翻会跳过太多。
    function stepSize() {
      var item = list.querySelector(".igv__item");
      if (!item) return list.clientWidth;
      var styles = window.getComputedStyle(list);
      var gap = parseFloat(styles.columnGap || styles.gap || "0") || 0;
      return item.getBoundingClientRect().width + gap;
    }

    // 圆点按「屏」算，不按条目算：列数由 CSS 变量控制，条目数和可翻页数不是一回事。
    function pages() {
      if (list.clientWidth <= 0) return 1;
      return Math.max(1, Math.ceil(list.scrollWidth / list.clientWidth));
    }

    function activePage() {
      if (list.clientWidth <= 0) return 0;
      var index = Math.round(list.scrollLeft / list.clientWidth);
      return Math.min(Math.max(index, 0), pages() - 1);
    }

    function buildDots() {
      if (!dotsBox) return;

      var total = pages();
      pageCount = total;
      dotsBox.textContent = "";

      // 只有一屏就没有位置可言，容器留空即可（CSS 里没有内容自然不占位）。
      if (total < 2) return;

      for (var index = 0; index < total; index++) {
        var dot = document.createElement("button");
        dot.type = "button";
        dot.className = "igv__dot";
        // 用 aria-current 而不是 role="tab"：tab 角色要求配套的 tabpanel，
        // 这里没有面板可指，写了反而让读屏软件报结构错误。
        dot.setAttribute("aria-label", "第 " + (index + 1) + " 屏");
        dot.dataset.page = String(index);
        dot.addEventListener("click", function (event) {
          var page = parseInt(event.currentTarget.dataset.page, 10) || 0;
          list.scrollTo({ left: page * list.clientWidth, behavior: "smooth" });
        });
        dotsBox.appendChild(dot);
      }
    }

    function syncDots() {
      if (!dotsBox || pageCount < 2) return;
      var current = activePage();
      var dots = dotsBox.children;
      for (var index = 0; index < dots.length; index++) {
        // 不 disable 当前圆点：那会把它移出 Tab 序列，键盘用户就跳不回来了。
        // 点当前页只是滚到原位，无副作用。
        if (index === current) {
          dots[index].setAttribute("aria-current", "true");
        } else {
          dots[index].removeAttribute("aria-current");
        }
      }
    }

    var update = throttle(function () {
      var maxScroll = list.scrollWidth - list.clientWidth - 2;
      // 到边界用禁用而不是隐藏：隐藏会让另一侧按钮的位置发生跳动。
      if (prev) prev.disabled = list.scrollLeft <= 2;
      if (next) next.disabled = list.scrollLeft >= maxScroll;
      syncDots();
    });

    // 视口变化会改变一屏的条数，页数跟着变，圆点必须重建。
    var rebuild = throttle(function () {
      if (pages() !== pageCount) {
        buildDots();
      }
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

    // 图片是懒加载的，加载完 scrollWidth 才是最终值，所以载入后再校一次。
    window.addEventListener("load", rebuild);

    buildDots();
    update();
  }

  /* --------------------- 帖子弹窗 --------------------- */

  function buildLightbox() {
    if (lightbox) return lightbox;

    lightbox = document.createElement("div");
    lightbox.className = "igv-lightbox";
    lightbox.hidden = true;
    lightbox.setAttribute("role", "dialog");
    lightbox.setAttribute("aria-modal", "true");
    lightbox.setAttribute("aria-label", "Instagram 帖子");

    lightbox.innerHTML = [
      '<div class="igv-lightbox__dialog">',
      '  <button class="igv-lightbox__button igv-lightbox__close" type="button" aria-label="关闭">&times;</button>',
      '  <button class="igv-lightbox__button igv-lightbox__prev" type="button" aria-label="上一条">&#8249;</button>',
      '  <button class="igv-lightbox__button igv-lightbox__next" type="button" aria-label="下一条">&#8250;</button>',
      '  <div class="igv-lightbox__frame">',
      '    <p class="igv-lightbox__loading">正在从 Instagram 加载帖子…</p>',
      '    <iframe',
      '      class="igv-lightbox__embed"',
      '      title="Instagram 帖子"',
      '      frameborder="0"',
      '      scrolling="no"',
      '      allowtransparency="true"',
      '      allow="encrypted-media; picture-in-picture"',
      '      referrerpolicy="strict-origin-when-cross-origin"',
      "    ></iframe>",
      "  </div>",
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

    // iframe 加载完再撤掉占位文案。跨域读不到内容，只能靠 load 事件。
    lightbox
      .querySelector(".igv-lightbox__embed")
      .addEventListener("load", function () {
        var loading = lightbox.querySelector(".igv-lightbox__loading");
        if (loading) loading.hidden = true;
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
    var frame = lightbox.querySelector(".igv-lightbox__embed");
    var loading = lightbox.querySelector(".igv-lightbox__loading");
    var caption = lightbox.querySelector(".igv-lightbox__caption");
    var links = lightbox.querySelector(".igv-lightbox__links");

    if (item.embed_url) {
      if (loading) loading.hidden = false;
      frame.hidden = false;
      frame.src = item.embed_url;
    } else {
      // 没有可嵌地址时只保留下面的外链，不留一个空白 iframe。
      frame.hidden = true;
      frame.removeAttribute("src");
      if (loading) loading.hidden = true;
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

  function openLightbox(items, index, opener) {
    if (!items.length) return;

    buildLightbox();
    lightboxState.items = items;
    lightboxState.opener = opener;

    lightbox.hidden = false;
    document.documentElement.style.overflow = "hidden";
    showLightboxItem(index);
    lightbox.querySelector(".igv-lightbox__close").focus();
  }

  function closeLightbox() {
    if (!lightbox || lightbox.hidden) return;

    // 必须清掉 src：留着的话 Instagram 的 iframe 会继续在后台跑（有声音的视频尤其明显）。
    var frame = lightbox.querySelector(".igv-lightbox__embed");
    frame.removeAttribute("src");

    lightbox.hidden = true;
    document.documentElement.style.overflow = "";

    if (lightboxState.opener && lightboxState.opener.focus) {
      lightboxState.opener.focus();
    }
    lightboxState.opener = null;
  }

  function setupLightbox(root) {
    // 商家选了「跳转到 Instagram 帖子」时，封面已经是服务端渲染的 <a>，这里不接管。
    if (root.dataset.clickAction !== "modal") return;

    var items = readItems(root);
    if (!items.length) return;

    root.querySelectorAll("[data-igv-open]").forEach(function (trigger) {
      trigger.addEventListener("click", function () {
        var index = parseInt(trigger.getAttribute("data-igv-open"), 10);
        openLightbox(items, isNaN(index) ? 0 : index, trigger);
      });
    });
  }

  /* ---------------------- 初始化 ---------------------- */

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

  // 主题编辑器里增删/移动区块后重新初始化
  document.addEventListener("shopify:section:load", function (event) {
    initAll(event.target);
  });
  document.addEventListener("shopify:block:select", function (event) {
    initAll(event.target);
  });
})();
