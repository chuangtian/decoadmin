/*
 * Instagram 内容 app block：轮播/网格 + 点击弹窗看原帖。
 *
 * 只展示转存到 R2 的封面图，不自己播视频 —— Instagram 对部分 Reels（用了平台授权
 * 音乐、或关闭了「允许下载」）不返回视频文件地址。点击封面弹窗里嵌 Instagram 官方
 * embed：视频与轮播由 Instagram 自己渲染，不涉及版权，也不需要令牌。
 *
 * 弹窗骨架由 liquid 的 <template> 提供，这里只克隆。注意本文件受 Shopify app block
 * 的 10KB 限额约束，加代码前先看体积。
 */
(function () {
  "use strict";

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

  /* ----------------------- 轮播 ----------------------- */

  function setupCarousel(root) {
    if (root.dataset.layout !== "carousel") return;

    var list = pick(root, "list");
    if (!list) return;

    var prev = pick(root, "prev");
    var next = pick(root, "next");
    var dotsBox = pick(root, "dots");
    var pageCount = 0;

    // 一次翻一条（含间距）而不是一整屏：桌面端一屏 4 条时整屏翻会跳过太多。
    function stepSize() {
      var item = list.querySelector(".igv__item");
      if (!item) return list.clientWidth;
      var styles = window.getComputedStyle(list);
      var gap = parseFloat(styles.columnGap || styles.gap || "0") || 0;
      return item.getBoundingClientRect().width + gap;
    }

    // 圆点按「屏」算而不是按条目：列数由 CSS 变量控制，条目数不等于可翻页数。
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
        // 用 aria-current 而不是 role="tab"：tab 角色要求配套 tabpanel，这里没有。
        dot.setAttribute("aria-label", "第 " + (i + 1) + " 屏");
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
        // 不 disable 当前项：那会把它移出 Tab 序列。点当前页只是滚回原位。
        if (i === current) dots[i].setAttribute("aria-current", "true");
        else dots[i].removeAttribute("aria-current");
      }
    }

    var update = throttle(function () {
      var max = list.scrollWidth - list.clientWidth - 2;
      // 到边界用禁用而非隐藏：隐藏会让另一侧按钮的位置跳动。
      if (prev) prev.disabled = list.scrollLeft <= 2;
      if (next) next.disabled = list.scrollLeft >= max;
      syncDots();
    });

    // 视口变化会改变一屏条数，页数跟着变，圆点必须重建。
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
    // 图片懒加载，载入后 scrollWidth 才是最终值。
    window.addEventListener("load", rebuild);

    buildDots();
    update();
  }

  /* --------------------- 帖子弹窗 --------------------- */

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

    if (item.embed_url) {
      box.loading.hidden = false;
      box.frame.hidden = false;
      box.frame.src = item.embed_url;
    } else {
      // 没有可嵌地址时只留下面的外链，不留一个空白 iframe。
      box.frame.hidden = true;
      box.frame.removeAttribute("src");
      box.loading.hidden = true;
    }

    box.caption.textContent = item.caption || "";
    box.caption.hidden = !item.caption;

    box.links.textContent = "";
    (item.products || []).forEach(function (product) {
      if (product && product.url) {
        box.links.appendChild(link(product.url, product.title || "查看商品"));
      }
    });
    if (item.permalink) {
      box.links.appendChild(link(item.permalink, "在 Instagram 查看", true));
    }

    box.prev.hidden = total < 2;
    box.next.hidden = total < 2;
  }

  function close() {
    if (!box || box.root.hidden) return;

    // 必须清 src：留着的话 Instagram 的 iframe 会继续在后台跑（有声视频尤其明显）。
    box.frame.removeAttribute("src");
    box.root.hidden = true;
    document.documentElement.style.overflow = "";

    if (state.opener && state.opener.focus) state.opener.focus();
    state.opener = null;
  }

  function setupLightbox(root) {
    // 商家选了「跳转到 Instagram 帖子」时，封面已是服务端渲染的 <a>，这里不接管。
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
